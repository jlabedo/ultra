<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler;

use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\MediatorInterface;
use Ultra\MessageHandling\MessageContextPropagator;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling_Compiler\AdviceCollector;
use Ultra\MessageHandling_Compiler\DecoratingDependencyExpressionResolver;
use Ultra\MessageHandling_Compiler\DispatchPlanRenderer;
use Ultra\MessageHandling_Compiler\HandlerDescriptor;
use Ultra\MessageHandling_Compiler\HandlerDescriptorFactory;
use Ultra\MessageHandling_Compiler\HandlerMethodGenerator;
use Ultra\MessageHandling_Compiler\LocatorReferenceCollector;

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
 *  Phase 1b — Self-handler discovery:
 *    Find all services tagged with $selfHandlerTag (default: "mediator.self-handler").
 *    These are message classes that handle themselves: the #[Handles] method is on the
 *    message class itself, takes only dep params (no message param — $this is the message),
 *    and is invoked as $message->method(...deps) at runtime. The message class is not added
 *    to the service locator. The service ID sentinel '@self' signals the generator.
 *
 *  Phase 2 — Aspect matching:
 *    Find all services tagged with $aspectTag (default: "mediator.aspect").
 *    Reflect on each aspect class; scan public methods for #[Around] and #[Before].
 *    For each attribute, call its $matcher closure with the handler's ReflectionMethod.
 *    A non-null MatchResult means "apply this advice":
 *      $result->provides  list<int>  dep-param slots this advice provides at runtime
 *      $result->config    array      serializable config embedded in the generated dispatch method
 *    Collect all matching advices; ordering happens in MediatorGenerator based on
 *    priority and advice type.
 *
 *  Phase 3 — Code generation and registration:
 *    Build a handler map and pass it to MediatorGenerator.
 *    Register the generated mediator with a ServiceLocatorArgument carrying all
 *    handler, dep, and aspect services. Dep slots claimed by around advices are
 *    excluded from the locator.
 *
 * The container loads the generated file automatically via Definition::setFile().
 */
final class MediatorCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $outputDir,
        private readonly string $generatedClass = 'CompiledMediator',
        private readonly string $handlerTag = 'mediator.handler',
        private readonly string $aspectTag = 'mediator.aspect',
        private readonly string $selfHandlerTag = 'mediator.self-handler',
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $this->ensureMessageContextPropagator($container);

        $adviceCollector = new AdviceCollector($this->aspectTag);
        $descriptorFactory = new HandlerDescriptorFactory();
        $locatorReferences = new LocatorReferenceCollector();
        $taggedHandlers = $container->findTaggedServiceIds($this->handlerTag);
        $taggedSelfHandlers = $container->findTaggedServiceIds($this->selfHandlerTag);

        if ([] === $taggedHandlers && [] === $taggedSelfHandlers) {
            return;
        }

        /** @var array<class-string, HandlerDescriptor> $handlers */
        $handlers = [];
        /** @var array<class-string, \ReflectionMethod> $handlerReflections */
        $handlerReflections = [];

        // ── Phase 1: discover handler methods ────────────────────────────────
        foreach (array_keys($taggedHandlers) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;

            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ([] === $method->getAttributes(Handles::class)) {
                    continue;
                }

                $params = $method->getParameters();
                if ([] === $params) {
                    continue;
                }

                $firstType = $params[0]->getType();
                if (! $firstType instanceof \ReflectionNamedType || $firstType->isBuiltin()) {
                    continue;
                }

                $messageClass = $firstType->getName();

                if (isset($handlers[$messageClass])) {
                    throw new \LogicException(\sprintf(
                        'Duplicate handler for message "%s": already registered by service "%s", cannot also register "%s".',
                        $messageClass,
                        $handlers[$messageClass]->serviceId,
                        $serviceId,
                    ));
                }

                $handlers[$messageClass] = $descriptorFactory->create($serviceId, $class, $method);
                $handlerReflections[$messageClass] = $method;
                $locatorReferences->addService($serviceId);
            }
        }

        // ── Phase 1b: discover self-handler message classes ───────────────────
        // The message class IS the handler: #[Handles] method has only dep params
        // (no message param — $this is the message). Generated code: $message->method(...deps).
        foreach (array_keys($taggedSelfHandlers) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;

            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ([] === $method->getAttributes(Handles::class)) {
                    continue;
                }

                $messageClass = $class;

                if (isset($handlers[$messageClass])) {
                    throw new \LogicException(\sprintf(
                        'Duplicate handler for message "%s": already registered by service "%s", cannot also register "%s" as a self-handler.',
                        $messageClass,
                        $handlers[$messageClass]->serviceId,
                        $serviceId,
                    ));
                }

                // All params are deps — no message param to skip
                // '@self' sentinel: generator emits $message->method(...) instead of $locator->get(...)
                $handlers[$messageClass] = $descriptorFactory->create('@self', $class, $method, skipFirstParameter: false);
                $handlerReflections[$messageClass] = $method;
                // Message class is NOT added to the locator — it is passed directly at dispatch time
            }
        }

        if ([] === $handlers) {
            return;
        }

        // ── Phase 2: match aspect services to each handler ───────────────────
        foreach ($handlers as $messageClass => $handlerDescriptor) {
            $handlerMethod = $handlerReflections[$messageClass];
            $advices = $adviceCollector->collect($handlerMethod, $container);

            $handlers[$messageClass] = $handlerDescriptor->withAdvices($advices);
            $locatorReferences->addHandlerDependencies($handlerDescriptor, $advices);
        }

        // ── Phase 3: generate and register ───────────────────────────────────
        $filePath = rtrim($this->outputDir, '/').'/'.$this->generatedClass.'.php';
        (new MediatorGenerator(
            new HandlerMethodGenerator(
                renderer: new DispatchPlanRenderer([
                    new DecoratingDependencyExpressionResolver(
                        'Ultra\\EventBus\\EventBusInterface',
                        'Ultra\\EventBus\\ContextPropagatingEventBus',
                    ),
                    new DecoratingDependencyExpressionResolver(
                        MediatorInterface::class,
                        'Ultra\\Mediator\\ContextPropagatingMediator',
                    ),
                ]),
            ),
        ))->generate($this->generatedClass, $handlers, $filePath);

        $mediatorDef = new Definition($this->generatedClass, [
            new ServiceLocatorArgument($this->serviceLocatorReferences($locatorReferences->all())),
            new Reference(MessageContextPropagatorInterface::class),
        ]);
        $mediatorDef->setFile($filePath)->setPublic(true);
        $container->setDefinition(MediatorInterface::class, $mediatorDef);
    }

    /**
     * @param list<string> $serviceIds
     * @return array<string, Reference>
     */
    private function serviceLocatorReferences(array $serviceIds): array
    {
        $references = [];
        foreach ($serviceIds as $serviceId) {
            $references[$serviceId] = new Reference($serviceId);
        }

        return $references;
    }

    private function ensureMessageContextPropagator(ContainerBuilder $container): void
    {
        if ($container->has(MessageContextPropagatorInterface::class)) {
            return;
        }

        $container->setDefinition(
            MessageContextPropagatorInterface::class,
            (new Definition(MessageContextPropagator::class))->setPublic(true),
        );
    }
}
