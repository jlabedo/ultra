<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler;

use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Ultra\Mediator\Attribute\AdviceDescriptor;
use Ultra\Mediator\Attribute\AspectAttribute;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\Attribute\ParameterProvidingAspect;
use Ultra\Mediator\MediatorInterface;

/**
 * Symfony DI compiler pass that generates a zero-overhead compiled mediator.
 *
 * Flow:
 *  1. Find all services tagged with $handlerTag (default: "mediator.handler").
 *  2. Reflect on each class to discover public methods marked with #[Handles].
 *  3. For each #[Handles] method, also collect any attributes implementing AspectAttribute.
 *     Attributes implementing ParameterProvidingAspect additionally declare which dep
 *     parameters they will supply at runtime (claimedDepIndices) and carry serializable
 *     runtimeConfig that is embedded as a PHP literal in the generated dispatch method.
 *  4. Build a handler map: messageClass => [serviceId, methodName, depServiceIds, advices].
 *  5. Generate a concrete PHP class (via MediatorGenerator) with one private dispatch method
 *     per message type, inlining all AOP advice code.
 *  6. Register the generated class as the MediatorInterface service, wired to a
 *     ServiceLocator containing every handler, its non-claimed deps, and aspect services.
 *     Claimed dep types are NOT added to the locator — the aspect injects them directly.
 *
 * The generated file must be on an autoloaded path at runtime. Place $outputDir inside
 * your framework's cache directory and ensure it is classmap-autoloaded.
 */
final class MediatorCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $outputDir,
        private readonly string $generatedClass = 'CompiledMediator',
        private readonly string $handlerTag = 'mediator.handler',
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $tagged = $container->findTaggedServiceIds($this->handlerTag);

        if ($tagged === []) {
            return;
        }

        /** @var array<class-string, array{0: string, 1: string, 2: list<string>, 3: list<AdviceDescriptor>}> $handlers */
        $handlers = [];
        /** @var array<string, ServiceClosureArgument> $locatorServices */
        $locatorServices = [];

        foreach (array_keys($tagged) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;

            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(Handles::class) === []) {
                    continue;
                }

                $params = $method->getParameters();
                if ($params === []) {
                    continue;
                }

                $firstType = $params[0]->getType();
                if (!$firstType instanceof \ReflectionNamedType || $firstType->isBuiltin()) {
                    continue;
                }

                $messageClass = $firstType->getName();

                if (isset($handlers[$messageClass])) {
                    throw new \LogicException(sprintf(
                        'Duplicate handler for message "%s": already registered by service "%s", cannot also register "%s".',
                        $messageClass,
                        $handlers[$messageClass][0],
                        $serviceId,
                    ));
                }

                $depServiceIds = $this->resolveDepServiceIds($method);
                $advices = $this->resolveAdvices($method);

                $handlers[$messageClass] = [$serviceId, $method->getName(), $depServiceIds, $advices];

                $locatorServices[$serviceId] = new ServiceClosureArgument(new Reference($serviceId));

                // Collect dep indices claimed by parameter-providing aspects so they are
                // excluded from the locator — the aspect service injects them directly.
                $claimedIndices = [];
                foreach ($advices as $advice) {
                    foreach ($advice->claimedDepIndices as $idx) {
                        $claimedIndices[$idx] = true;
                    }
                }

                foreach ($depServiceIds as $i => $depId) {
                    if (!isset($claimedIndices[$i])) {
                        $locatorServices[$depId] = new ServiceClosureArgument(new Reference($depId));
                    }
                }

                foreach ($advices as $advice) {
                    $locatorServices[$advice->serviceId] = new ServiceClosureArgument(new Reference($advice->serviceId));
                }
            }
        }

        if ($handlers === []) {
            return;
        }

        // Generate the concrete mediator class
        $filePath = rtrim($this->outputDir, '/') . '/' . $this->generatedClass . '.php';
        (new MediatorGenerator())->generate($this->generatedClass, $handlers, $filePath);

        // ServiceLocator — one per mediator, holds all handlers, their deps, and aspect services
        $locatorId = 'ultra.mediator.locator';
        $locatorDef = new Definition(ServiceLocator::class, [$locatorServices]);
        $locatorDef->setPublic(false)->addTag('container.service_locator');
        $container->setDefinition($locatorId, $locatorDef);

        // The generated mediator service, autowirable via MediatorInterface
        $mediatorDef = new Definition($this->generatedClass, [new Reference($locatorId)]);
        $mediatorDef->setPublic(true);
        $container->setDefinition(MediatorInterface::class, $mediatorDef);
    }

    /** @return list<string> */
    private function resolveDepServiceIds(\ReflectionMethod $method): array
    {
        $ids = [];
        foreach (array_slice($method->getParameters(), 1) as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new \LogicException(sprintf(
                    'Parameter "$%s" of handler method "%s::%s" must have a non-builtin type.',
                    $param->getName(),
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                ));
            }
            $ids[] = $type->getName();
        }

        return $ids;
    }

    /** @return list<AdviceDescriptor> */
    private function resolveAdvices(\ReflectionMethod $method): array
    {
        $depParams = array_slice($method->getParameters(), 1);

        $advices = [];
        foreach ($method->getAttributes() as $refAttr) {
            $instance = $refAttr->newInstance();
            if (!$instance instanceof AspectAttribute) {
                continue;
            }

            $base = $instance->getAdviceDescriptor();

            if ($instance instanceof ParameterProvidingAspect) {
                $resolution = $instance->compileParameterResolution($depParams);
                $advices[] = new AdviceDescriptor(
                    $base->type,
                    $base->serviceId,
                    $base->method,
                    $resolution['indices'],
                    $resolution['config'],
                );
            } else {
                $advices[] = $base;
            }
        }

        return $advices;
    }
}
