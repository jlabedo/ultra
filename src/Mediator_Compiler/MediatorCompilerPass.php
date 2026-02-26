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
use Ultra\Mediator\Attribute\AdviceType;
use Ultra\Mediator\Attribute\After;
use Ultra\Mediator\Attribute\AfterReturning;
use Ultra\Mediator\Attribute\AfterThrowing;
use Ultra\Mediator\Attribute\Around;
use Ultra\Mediator\Attribute\Before;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\Attribute\MatchResult;
use Ultra\Mediator\MediatorInterface;

/**
 * Symfony DI compiler pass that generates a zero-overhead compiled mediator.
 *
 * Flow:
 *  Phase 1 — Handler discovery:
 *    Find all services tagged with $handlerTag (default: "mediator.handler").
 *    Reflect on each class; collect every public method marked with #[Handles].
 *    For each such method, record the message class, service ID, method name,
 *    and non-message dep service IDs.
 *
 *  Phase 2 — Aspect matching:
 *    Find all services tagged with $aspectTag (default: "mediator.aspect").
 *    Reflect on each aspect class; scan public methods for #[Around] and #[Before].
 *    For each attribute, call its $matcher closure with the handler's ReflectionMethod.
 *    A non-null MatchResult means "apply this advice":
 *      $result->provides  list<int>  dep-param slots this advice provides at runtime
 *      $result->config    array      serializable config embedded in the generated dispatch method
 *    Collect all matching advices, sort by priority (descending = outermost first).
 *
 *  Phase 3 — Code generation and registration:
 *    Build a handler map and pass it to MediatorGenerator.
 *    Register a ServiceLocator with all handler, dep, and aspect services.
 *    Dep slots claimed by around advices are excluded from the locator.
 *
 * The generated file must be on an autoloaded path at runtime.
 */
final class MediatorCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $outputDir,
        private readonly string $generatedClass = 'CompiledMediator',
        private readonly string $handlerTag = 'mediator.handler',
        private readonly string $aspectTag = 'mediator.aspect',
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $taggedHandlers = $container->findTaggedServiceIds($this->handlerTag);

        if ($taggedHandlers === []) {
            return;
        }

        /** @var array<class-string, array{0: string, 1: string, 2: list<string>, 3: list<AdviceDescriptor>}> $handlers */
        $handlers = [];
        /** @var array<class-string, \ReflectionMethod> $handlerReflections */
        $handlerReflections = [];
        /** @var array<string, ServiceClosureArgument> $locatorServices */
        $locatorServices = [];

        // ── Phase 1: discover handler methods ────────────────────────────────
        foreach (array_keys($taggedHandlers) as $serviceId) {
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
                $handlers[$messageClass] = [$serviceId, $method->getName(), $depServiceIds, []];
                $handlerReflections[$messageClass] = $method;

                $locatorServices[$serviceId] = new ServiceClosureArgument(new Reference($serviceId));
            }
        }

        if ($handlers === []) {
            return;
        }

        // ── Phase 2: match aspect services to each handler ───────────────────
        $taggedAspects = $container->findTaggedServiceIds($this->aspectTag);

        foreach ($handlers as $messageClass => &$handlerData) {
            $handlerMethod = $handlerReflections[$messageClass];
            $rawAdvices = $this->collectAdvices($handlerMethod, $taggedAspects, $container);

            // Higher priority → outermost around advice / first before advice
            usort($rawAdvices, static fn($a, $b) => $b['priority'] <=> $a['priority']);

            $advices = array_map(
                static fn($r) => new AdviceDescriptor($r['type'], $r['serviceId'], $r['method'], $r['indices'], $r['config']),
                $rawAdvices,
            );

            $handlerData[3] = $advices;

            // Claimed dep slots are provided by the aspect — exclude from locator
            $claimedIndices = [];
            foreach ($advices as $advice) {
                foreach ($advice->claimedDepIndices as $idx) {
                    $claimedIndices[$idx] = true;
                }
            }

            foreach ($handlerData[2] as $i => $depId) {
                if (!isset($claimedIndices[$i])) {
                    $locatorServices[$depId] = new ServiceClosureArgument(new Reference($depId));
                }
            }

            foreach ($advices as $advice) {
                $locatorServices[$advice->serviceId] = new ServiceClosureArgument(new Reference($advice->serviceId));
            }
        }
        unset($handlerData);

        // ── Phase 3: generate and register ───────────────────────────────────
        $filePath = rtrim($this->outputDir, '/') . '/' . $this->generatedClass . '.php';
        (new MediatorGenerator())->generate($this->generatedClass, $handlers, $filePath);

        $locatorId = 'ultra.mediator.locator';
        $locatorDef = new Definition(ServiceLocator::class, [$locatorServices]);
        $locatorDef->setPublic(false)->addTag('container.service_locator');
        $container->setDefinition($locatorId, $locatorDef);

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

    /**
     * Scan all aspect services for #[Around] and #[Before] methods that match $handlerMethod.
     *
     * @param  array<string, mixed>  $taggedAspects
     * @return list<array{type: AdviceType, serviceId: string, method: string, priority: int, indices: list<int>, config: array}>
     *
     * The matcher closure must return a MatchResult (or null to skip).
     */
    private function collectAdvices(
        \ReflectionMethod $handlerMethod,
        array $taggedAspects,
        ContainerBuilder $container,
    ): array {
        $rawAdvices = [];

        foreach (array_keys($taggedAspects) as $aspectServiceId) {
            $definition = $container->getDefinition($aspectServiceId);
            $aspectClass = $definition->getClass() ?? $aspectServiceId;

            foreach ((new \ReflectionClass($aspectClass))->getMethods(\ReflectionMethod::IS_PUBLIC) as $aspectMethod) {
                foreach ($aspectMethod->getAttributes(Around::class) as $refAttr) {
                    /** @var Around $around */
                    $around = $refAttr->newInstance();
                    /** @var MatchResult|null $result */
                    $result = ($around->matcher)($handlerMethod);
                    if ($result === null) {
                        continue;
                    }
                    $rawAdvices[] = [
                        'type'      => AdviceType::Around,
                        'serviceId' => $aspectServiceId,
                        'method'    => $aspectMethod->getName(),
                        'priority'  => $around->priority,
                        'indices'   => $result->provides,
                        'config'    => $result->config,
                    ];
                }

                foreach ($aspectMethod->getAttributes(Before::class) as $refAttr) {
                    /** @var Before $before */
                    $before = $refAttr->newInstance();
                    /** @var MatchResult|null $result */
                    $result = ($before->matcher)($handlerMethod);
                    if ($result === null) {
                        continue;
                    }
                    $rawAdvices[] = [
                        'type'      => AdviceType::Before,
                        'serviceId' => $aspectServiceId,
                        'method'    => $aspectMethod->getName(),
                        'priority'  => $before->priority,
                        'indices'   => [],
                        'config'    => $result->config,
                    ];
                }

                foreach ($aspectMethod->getAttributes(AfterReturning::class) as $refAttr) {
                    /** @var AfterReturning $afterReturning */
                    $afterReturning = $refAttr->newInstance();
                    /** @var MatchResult|null $result */
                    $result = ($afterReturning->matcher)($handlerMethod);
                    if ($result === null) {
                        continue;
                    }
                    $rawAdvices[] = [
                        'type'      => AdviceType::AfterReturning,
                        'serviceId' => $aspectServiceId,
                        'method'    => $aspectMethod->getName(),
                        'priority'  => $afterReturning->priority,
                        'indices'   => [],
                        'config'    => $result->config,
                    ];
                }

                foreach ($aspectMethod->getAttributes(AfterThrowing::class) as $refAttr) {
                    /** @var AfterThrowing $afterThrowing */
                    $afterThrowing = $refAttr->newInstance();
                    /** @var MatchResult|null $result */
                    $result = ($afterThrowing->matcher)($handlerMethod);
                    if ($result === null) {
                        continue;
                    }
                    $rawAdvices[] = [
                        'type'      => AdviceType::AfterThrowing,
                        'serviceId' => $aspectServiceId,
                        'method'    => $aspectMethod->getName(),
                        'priority'  => $afterThrowing->priority,
                        'indices'   => [],
                        'config'    => $result->config,
                    ];
                }

                foreach ($aspectMethod->getAttributes(After::class) as $refAttr) {
                    /** @var After $after */
                    $after = $refAttr->newInstance();
                    /** @var MatchResult|null $result */
                    $result = ($after->matcher)($handlerMethod);
                    if ($result === null) {
                        continue;
                    }
                    $rawAdvices[] = [
                        'type'      => AdviceType::After,
                        'serviceId' => $aspectServiceId,
                        'method'    => $aspectMethod->getName(),
                        'priority'  => $after->priority,
                        'indices'   => [],
                        'config'    => $result->config,
                    ];
                }
            }
        }

        return $rawAdvices;
    }
}
