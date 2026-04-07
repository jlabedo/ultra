<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MessageHandling\Async\AsyncHandlerProxy;
use Ultra\MessageHandling\Async\AsyncQueueInterface;
use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\Attribute\Advice;
use Ultra\MessageHandling\Attribute\AdvicePhase;
use Ultra\MessageHandling\Attribute\MultiDispatch;
use Ultra\MessageHandling\Attribute\SingleDispatch;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextPropagator;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling\TagNames;
use Ultra\MessageHandling_Compiler\Handler\AdviceCollector;
use Ultra\MessageHandling_Compiler\Handler\AdviceDefinition;
use Ultra\MessageHandling_Compiler\Handler\AdviceType;
use Ultra\MessageHandling_Compiler\Handler\CompileTimeHandlerMatchContext;
use Ultra\MessageHandling_Compiler\Handler\DispatchPlanRenderer;
use Ultra\MessageHandling_Compiler\Handler\HandlerClassGenerator;
use Ultra\MessageHandling_Compiler\Handler\HandlerDescriptor;
use Ultra\MessageHandling_Compiler\Handler\HandlerDescriptorFactory;
use Ultra\MessageHandling_Compiler\Routing\ContextualRouterDependencyResolver;
use Ultra\MessageHandling_Compiler\Routing\MessageRouterGenerator;
use Ultra\MessageHandling_Compiler\Routing\RouterDescriptor;

/**
 * Unified Symfony DI compiler pass that generates compiled router classes for
 * any interface tagged with ultra.message_router.
 *
 * Flow:
 *  1. Discover routers: find definitions tagged $routerTag; each carries interface FQCN metadata.
 *     Reflect on the interface to find #[SingleDispatch] or #[MultiDispatch] on a method.
 *     Build RouterDescriptor list.
 *
 *  2. Discover handlers: find all services/resources tagged $handlerTag. Service-tagged
 *     definitions are regular handlers, resource-tagged definitions are self handlers.
 *     Build a flat pool of handler descriptors and their reflection methods.
 *
 *  3. For each router, match handlers via the descriptor's matcher closure:
 *     - SingleDispatch: enforce one handler per message class
 *     - MultiDispatch: collect subscriptions, resolve via is_a() against known message classes,
 *       sort by priority
 *
 *  4. Build ContextualRouterDependencyResolver from the discovered routers, mapping each
 *     router service ID (and its aliases) to its generated contextual wrapper class name.
 *
 *  5. For each router, generate per-handler service classes,
 *     generate thin router class, register services.
 */
final readonly class MessageRouterCompilerPass implements CompilerPassInterface
{
    public function __construct(private string $outputDir) {}

    public function process(ContainerBuilder $container): void
    {
        $this->ensureMessageContextPropagator($container);
        $this->ensureAsyncQueue($container);

        // ── Step 1: Discover router interfaces ────────────────────────────────
        /** @var array<string, array{descriptor: RouterDescriptor, baseName: string, outputDir: string}> $routers */
        $routers = [];
        /** @var array<string, array{descriptor: RouterDescriptor, baseName: string, wrapperClass: string}> $interfaceDescriptors keyed by interface FQCN */
        $interfaceDescriptors = [];

        foreach ($container->findTaggedServiceIds(TagNames::ROUTER) as $serviceId => $tags) {
            foreach ($tags as $tagAttributes) {
                $interfaceName = $tagAttributes['interface'] ?? $serviceId;

                if (! interface_exists($interfaceName)) {
                    continue;
                }

                if (! isset($interfaceDescriptors[$interfaceName])) {
                    $classRef = $container->getReflectionClass($interfaceName); // trigger class loading for better error messages
                    $descriptor = $this->buildRouterDescriptor($classRef);
                    if (null === $descriptor) {
                        continue;
                    }

                    $shortName = substr($interfaceName, strrpos($interfaceName, '\\') + 1);

                    $interfaceDescriptors[$interfaceName] = [
                        'descriptor' => $descriptor,
                        'baseName' => 'Compiled' . $shortName,
                        'wrapperClass' => MessageRouterGenerator::wrapperClassName($interfaceName, $this->outputDir),
                    ];
                }

                $routerOutputDir = rtrim($this->outputDir, '/') . '/' . substr($interfaceName, strrpos($interfaceName, '\\') + 1);
                $routers[$serviceId] = [
                    'descriptor' => $interfaceDescriptors[$interfaceName]['descriptor'],
                    'baseName' => $interfaceDescriptors[$interfaceName]['baseName'],
                    'outputDir' => $routerOutputDir,
                ];
            }
        }

        if ([] === $routers) {
            return;
        }

        // ── Step 4 (early): Build contextual wrapper mapping for all routers ─────
        $wrapperClassByServiceId = $this->buildWrapperClassMapping($routers, $interfaceDescriptors, $container);
        $resolver = new ContextualRouterDependencyResolver($wrapperClassByServiceId);

        // ── Steps 3 + 5: Match handlers, generate per-handler services + router ──
        $adviceCollector = new AdviceCollector($this->collectAdviceDefinitions($container));
        $descriptorFactory = new HandlerDescriptorFactory($adviceCollector);
        $handlerPool = $this->buildHandlerPool($container, $descriptorFactory);
        $expressionResolvers = [$resolver, new Handler\MessageContextDependencyResolver()];
        $handlerClassGenerator = new HandlerClassGenerator(
            renderer: new DispatchPlanRenderer($expressionResolvers),
        );
        $routerGenerator = new MessageRouterGenerator();

        // Generate shared contextual wrapper classes file
        $wrappersFilePath = $this->generateWrappersFile($routerGenerator, $interfaceDescriptors);

        foreach ($routers as $serviceId => ['descriptor' => $descriptor, 'baseName' => $baseName, 'outputDir' => $routerOutputDir]) {
            // Skip compilation for synthetic services (e.g. test doubles).
            // Their wrapper is still generated via the shared wrappers file,
            // but the service itself is not replaced.
            $existingDef = $container->hasDefinition($serviceId) ? $container->getDefinition($serviceId) : null;
            if (null !== $existingDef && $existingDef->isSynthetic()) {
                continue;
            }

            $routes = $descriptor->isSingleDispatch
                ? $this->matchSingleDispatch($descriptor, $handlerPool)
                : $this->matchMultiDispatch($descriptor, $handlerPool, $container);

            // Generate per-handler service classes and build simplified route map
            $handlerOutputDir = $routerOutputDir . '/handlers';
            /** @var array<class-string, string|list<string>> $routeMap */
            $routeMap = [];
            /** @var array<string, Reference> $routerLocatorRefs handler service IDs for router's locator */
            $routerLocatorRefs = [];
            /** @var array<string, string> $generatedHandlers dedup key => handler service ID */
            $generatedHandlers = [];

            foreach ($routes as $messageClass => $handlers) {
                $handlerServiceIds = [];
                foreach ($handlers as $handler) {
                    // For self-handlers, include the message class in the key
                    // since multiple commands can share serviceId='@self' + same method name.
                    $handlerKey = HandlerDescriptor::SELF_SERVICE_ID === $handler->serviceId
                        ? $messageClass . '::@self::' . $handler->method->getName()
                        : $handler->serviceId . '::' . $handler->method->getName();

                    if (! isset($generatedHandlers[$handlerKey])) {
                        $handlerServiceId = $this->generateHandlerService(
                            $handler,
                            $handlerClassGenerator,
                            $adviceCollector,
                            $expressionResolvers,
                            $handlerOutputDir,
                            $wrappersFilePath,
                            $container,
                        );
                        $generatedHandlers[$handlerKey] = $handlerServiceId;
                    }

                    $handlerServiceIds[] = $generatedHandlers[$handlerKey];
                }

                if ($descriptor->isSingleDispatch) {
                    $routeMap[$messageClass] = $handlerServiceIds[0];
                } else {
                    $routeMap[$messageClass] = $handlerServiceIds;
                }
            }

            // Collect all handler service IDs into the router's locator
            foreach ($generatedHandlers as $handlerServiceId) {
                $routerLocatorRefs[$handlerServiceId] = new Reference($handlerServiceId);
            }

            ['className' => $generatedClass, 'filePath' => $filePath] = $routerGenerator->generate(
                $baseName,
                $descriptor,
                $routeMap,
                $routerOutputDir,
            );

            $routerDef = new Definition($generatedClass, [
                new ServiceLocatorArgument($routerLocatorRefs),
                new Reference(MessageContextPropagatorInterface::class),
            ]);
            $routerDef->setFile($filePath)->setPublic(true);
            $container->setDefinition($serviceId, $routerDef);
        }
    }

    /**
     * @return list<HandlerDescriptor>
     */
    private function buildHandlerPool(ContainerBuilder $container, HandlerDescriptorFactory $descriptorFactory): array
    {
        $handlerPool = [];
        $taggedClasses = $container->findTaggedServiceIds(TagNames::HANDLER) + $container->findTaggedResourceIds(TagNames::SELF_HANDLER);

        foreach ($taggedClasses as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;

            if (! class_exists($class)) {
                continue;
            }

            $isSelfHandler = $definition->hasTag(TagNames::SELF_HANDLER);

            foreach ($tags as $tag) {
                $methodName = $tag['method'] ?? null;
                if (! $methodName || ! is_string($methodName)) {
                    throw new \LogicException(sprintf(
                        'Service "%s" tagged as "%s" must have a "method" attribute.',
                        $serviceId,
                        TagNames::HANDLER,
                    ));
                }
                $method = $container->getReflectionClass($class)->getMethod($methodName);
                $handler = $isSelfHandler ?
                    $descriptorFactory->createSelfHandler($class, $method) :
                    $descriptorFactory->createServiceHandler($serviceId, $class, $method);
                if (null === $handler) {
                    continue;
                }
                $handlerPool[] = $handler;
            }
        }

        return $handlerPool;
    }

    /**
     * Generate a handler service class and register it in the container.
     *
     * @return string The handler service ID
     */
    /**
     * @param list<HandlerDependencyExpressionResolverInterface> $expressionResolvers
     */
    private function generateHandlerService(
        HandlerDescriptor $handler,
        HandlerClassGenerator $handlerClassGenerator,
        AdviceCollector $adviceCollector,
        array $expressionResolvers,
        string $outputDir,
        string $wrappersFilePath,
        ContainerBuilder $container,
    ): string {
        $baseName = 'Handler_' . preg_replace('/[^A-Za-z0-9_]/', '_', $handler->serviceId) . '_' . $handler->method->getName();

        ['className' => $className, 'filePath' => $filePath] = $handlerClassGenerator->generate(
            $baseName,
            $handler,
            $outputDir,
            $wrappersFilePath,
        );

        // Build per-handler locator references
        $handlerLocator = new LocatorReferenceCollector($expressionResolvers);
        $handlerLocator->addHandlerDependencies($handler);

        $handlerServiceId = 'ultra.handler.' . $className;

        $handlerDef = new Definition($className, [
            new ServiceLocatorArgument($handlerLocator->toLocatorReferences()),
        ]);
        $handlerDef->setFile($filePath)->setPublic(false);

        if ($handler->isAsync) {
            // Register the real handler (execute-phase advices) under a ".original" ID
            $originalServiceId = $handlerServiceId . '.original';
            $handlerDef->setPublic(true); // QueueWorker needs to fetch it
            $container->setDefinition($originalServiceId, $handlerDef);

            // Register the proxy under a ".proxy" ID
            $proxyServiceId = $handlerServiceId . '.proxy';
            $proxyDef = new Definition(AsyncHandlerProxy::class, [
                new Reference(AsyncQueueInterface::class),
                $originalServiceId,
            ]);
            $proxyDef->setPublic(false);
            $container->setDefinition($proxyServiceId, $proxyDef);

            // Collect dispatch-phase advices for this handler
            $dispatchContext = new CompileTimeHandlerMatchContext(
                $handler->method,
                $handler->messageClass,
                true,
                HandlerDescriptor::SELF_SERVICE_ID === $handler->serviceId,
                AdvicePhase::Dispatch,
            );
            $dispatchAdvices = $adviceCollector->collect($dispatchContext);

            if ([] !== $dispatchAdvices) {
                // Generate an advised dispatch handler wrapping the proxy.
                // The proxy's __invoke(object $message, MessageContext $context)
                // takes the context as a dep — resolved to $c by MessageContextDependencyResolver.
                $proxyMethod = new \ReflectionMethod(AsyncHandlerProxy::class, '__invoke');
                $dispatchHandler = new HandlerDescriptor(
                    serviceId: $proxyServiceId,
                    method: $proxyMethod,
                    depServiceIds: [MessageContext::class],
                    advices: $dispatchAdvices,
                    className: AsyncHandlerProxy::class,
                    returnsVoid: true,
                    messageClass: $handler->messageClass,
                    isAsync: false,
                );

                $dispatchBaseName = $baseName . '_dispatch';
                ['className' => $dispatchClassName, 'filePath' => $dispatchFilePath] = $handlerClassGenerator->generate(
                    $dispatchBaseName,
                    $dispatchHandler,
                    $outputDir,
                    $wrappersFilePath,
                );

                $dispatchLocator = new LocatorReferenceCollector($expressionResolvers);
                $dispatchLocator->addHandlerDependencies($dispatchHandler);

                $dispatchDef = new Definition($dispatchClassName, [
                    new ServiceLocatorArgument($dispatchLocator->toLocatorReferences()),
                ]);
                $dispatchDef->setFile($dispatchFilePath)->setPublic(false);
                $container->setDefinition($handlerServiceId, $dispatchDef);
            } else {
                // No dispatch advices: use the bare proxy directly
                $container->setDefinition($handlerServiceId, $proxyDef);
            }
        } else {
            $container->setDefinition($handlerServiceId, $handlerDef);
        }

        return $handlerServiceId;
    }

    /**
     * @return list<AdviceDefinition>
     */
    private function collectAdviceDefinitions(ContainerBuilder $container): array
    {
        $adviceDefinitions = [];
        foreach ($container->findTaggedServiceIds(TagNames::ASPECT) as $aspectServiceId => $tags) {
            $class = $container->getDefinition($aspectServiceId)->getClass();
            $refClass = $container->getReflectionClass($class);
            foreach ($refClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $methodRef) {
                foreach ($methodRef->getAttributes(Advice::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                    /** @var Advice $advice */
                    $advice = $attr->newInstance();
                    $adviceType = AdviceType::fromAttribute($advice);
                    $returnsContextPatch = $this->assertValidAdviceMethod($class, $methodRef, $adviceType);
                    $adviceDefinitions[] = new AdviceDefinition(
                        $adviceType,
                        $advice->priority,
                        $class,
                        $methodRef->getName(),
                        $aspectServiceId,
                        $advice->matcher,
                        $returnsContextPatch,
                    );
                }
            }
        }

        return $adviceDefinitions;
    }

    private function assertValidAdviceMethod(string $class, \ReflectionMethod $methodRef, AdviceType $type): bool
    {
        $returnType = $methodRef->getReturnType();

        if (AdviceType::Around === $type) {
            if (
                ! $returnType instanceof \ReflectionNamedType
                || $returnType->isBuiltin()
                || ProceedResult::class !== $returnType->getName()
            ) {
                throw new \LogicException(sprintf(
                    'Around advice "%s::%s" must declare return type "%s".',
                    $class,
                    $methodRef->getName(),
                    ProceedResult::class,
                ));
            }

            return false;
        }

        if (null === $returnType) {
            return false;
        }

        if ($returnType instanceof \ReflectionNamedType) {
            if ($returnType->isBuiltin()) {
                return match ($returnType->getName()) {
                    'void' => false,
                    'array' => true,
                    default => throw new \LogicException(sprintf(
                        '%s advice "%s::%s" must declare no return type, "void", or return type "array" (optionally nullable).',
                        $type->name,
                        $class,
                        $methodRef->getName(),
                    )),
                };
            }

            throw new \LogicException(sprintf(
                '%s advice "%s::%s" must declare no return type, "void", or return type "array" (optionally nullable).',
                $type->name,
                $class,
                $methodRef->getName(),
            ));
        }

        if ($returnType instanceof \ReflectionUnionType) {
            $hasArray = false;

            foreach ($returnType->getTypes() as $namedType) {
                if (! $namedType instanceof \ReflectionNamedType) {
                    throw new \LogicException(sprintf(
                        '%s advice "%s::%s" must declare no return type, "void", or return type "array" (optionally nullable).',
                        $type->name,
                        $class,
                        $methodRef->getName(),
                    ));
                }

                if ($namedType->isBuiltin()) {
                    if ('array' === $namedType->getName()) {
                        $hasArray = true;

                        continue;
                    }

                    if ('null' !== $namedType->getName()) {
                        throw new \LogicException(sprintf(
                            '%s advice "%s::%s" must declare no return type, "void", or return type "array" (optionally nullable).',
                            $type->name,
                            $class,
                            $methodRef->getName(),
                        ));
                    }
                }

                if (! $namedType->isBuiltin()) {
                    throw new \LogicException(sprintf(
                        '%s advice "%s::%s" must declare no return type, "void", or return type "array" (optionally nullable).',
                        $type->name,
                        $class,
                        $methodRef->getName(),
                    ));
                }
            }

            if ($hasArray) {
                return true;
            }
        }

        throw new \LogicException(sprintf(
            '%s advice "%s::%s" must declare no return type, "void", or return type "array" (optionally nullable).',
            $type->name,
            $class,
            $methodRef->getName(),
        ));
    }

    /**
     * Reflect on an interface to find #[SingleDispatch] or #[MultiDispatch] on a method.
     */
    private function buildRouterDescriptor(\ReflectionClass $reflection): ?RouterDescriptor
    {
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $singleAttrs = $method->getAttributes(SingleDispatch::class);
            $multiAttrs = $method->getAttributes(MultiDispatch::class);

            if ([] !== $singleAttrs) {
                /** @var SingleDispatch $attr */
                $attr = $singleAttrs[0]->newInstance();
                $params = $method->getParameters();
                $firstParam = $params[0] ?? null;

                return new RouterDescriptor(
                    interfaceName: $reflection->getName(),
                    methodName: $method->getName(),
                    firstParamName: $firstParam?->getName() ?? 'message',
                    returnsVoid: $this->methodReturnsVoid($method),
                    matcher: $attr->matcher,
                    isSingleDispatch: true,
                    messageTag: null,
                    noHandlerExceptionClass: $attr->noHandlerExceptionClass,
                );
            }

            if ([] !== $multiAttrs) {
                /** @var MultiDispatch $attr */
                $attr = $multiAttrs[0]->newInstance();
                $params = $method->getParameters();
                $firstParam = $params[0] ?? null;

                return new RouterDescriptor(
                    interfaceName: $reflection->getName(),
                    methodName: $method->getName(),
                    firstParamName: $firstParam?->getName() ?? 'event',
                    returnsVoid: $this->methodReturnsVoid($method),
                    matcher: $attr->matcher,
                    isSingleDispatch: false,
                    messageTag: $attr->messageTag,
                    noHandlerExceptionClass: $attr->noHandlerExceptionClass,
                );
            }
        }

        return null;
    }

    /**
     * Match handlers for SingleDispatch routers.
     *
     * @param list<HandlerDescriptor> $handlerPool
     *
     * @return array<class-string, array{0: HandlerDescriptor}>
     */
    private function matchSingleDispatch(
        RouterDescriptor $descriptor,
        array $handlerPool,
    ): array {
        /** @var array<class-string, array{0: HandlerDescriptor}> $handlers */
        $handlers = [];

        foreach ($handlerPool as $handler) {
            $match = ($descriptor->matcher)($handler->method);
            if (null === $match) {
                continue;
            }

            $messageClass = $handler->messageClass;
            if (isset($handlers[$messageClass])) {
                throw new \LogicException(sprintf(
                    'Duplicate handler for message "%s": already registered by service "%s", cannot also register "%s".',
                    $messageClass,
                    $handlers[$messageClass][0]->serviceId,
                    $handler->serviceId,
                ));
            }

            $handlers[$messageClass] = [$handler->withPriority($match->priority)];
        }

        return $handlers;
    }

    /**
     * Match handlers for MultiDispatch routers using is_a() polymorphic routing.
     *
     * @param list<HandlerDescriptor> $handlerPool
     *
     * @return array<class-string, list<HandlerDescriptor>>
     */
    private function matchMultiDispatch(
        RouterDescriptor $descriptor,
        array $handlerPool,
        ContainerBuilder $container,
    ): array {
        /** @var array<string, list<HandlerDescriptor>> $subscriptions */
        $subscriptions = [];

        foreach ($handlerPool as $handler) {
            $match = ($descriptor->matcher)($handler->method);
            if (null === $match) {
                continue;
            }

            $subscriptionType = $handler->messageClass;
            $subscriptions[$subscriptionType][] = $handler->withPriority($match->priority);
        }

        /** @var array<class-string, list<HandlerDescriptor>> $routes */
        $routes = [];

        foreach (array_keys($container->findTaggedResourceIds($descriptor->messageTag)) as $resourceServiceId) {
            $definition = $container->getDefinition($resourceServiceId);
            $messageClass = $definition->getClass() ?? $resourceServiceId;

            /** @var list<HandlerDescriptor> $matchedHandlers */
            $matchedHandlers = [];

            foreach ($subscriptions as $subscriptionType => $entries) {
                if (! is_a($messageClass, $subscriptionType, true)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    $matchedHandlers[] = $entry;
                }
            }

            usort($matchedHandlers, static function (HandlerDescriptor $left, HandlerDescriptor $right): int {
                $priority = $right->priority <=> $left->priority;
                if (0 !== $priority) {
                    return $priority;
                }

                $service = $left->serviceId <=> $right->serviceId;
                if (0 !== $service) {
                    return $service;
                }

                return $left->method->getName() <=> $right->method->getName();
            });

            $routes[$messageClass] = $matchedHandlers;
        }

        return $routes;
    }

    /**
     * Generate a shared PHP file containing all contextual wrapper classes.
     *
     * Each handler class file will require_once this file so the wrappers
     * are available when handler methods reference them.
     *
     * @param array<string, array{descriptor: RouterDescriptor, baseName: string, wrapperClass: string}> $interfaceDescriptors
     */
    private function generateWrappersFile(MessageRouterGenerator $generator, array $interfaceDescriptors): string
    {
        $wrapperClassToDescriptor = [];
        foreach ($interfaceDescriptors as ['descriptor' => $descriptor, 'wrapperClass' => $wrapperClass]) {
            $wrapperClassToDescriptor[$wrapperClass] = $descriptor;
        }

        $filePath = rtrim($this->outputDir, '/') . '/_contextual_wrappers.php';
        $generator->generateWrappersFile($wrapperClassToDescriptor, $filePath);

        return $filePath;
    }

    /**
     * Build a mapping from router service IDs (and their aliases) to contextual
     * wrapper class names. This is used by the dependency resolver to wrap
     * router dependencies with context propagation at the call site.
     *
     * Uses both the compiled routers (from $routers) and the full set of known
     * interface descriptors to also cover services registered under a router
     * interface FQCN without the ROUTER tag (e.g. synthetic test doubles).
     *
     * @param array<string, array{descriptor: RouterDescriptor, baseName: string, outputDir: string}> $routers
     * @param array<string, array{descriptor: RouterDescriptor, baseName: string, wrapperClass: string}> $interfaceDescriptors
     *
     * @return array<string, string>
     */
    private function buildWrapperClassMapping(array $routers, array $interfaceDescriptors, ContainerBuilder $container): array
    {
        // Build a lookup from interface FQCN → wrapper class name
        $wrapperByInterface = [];
        foreach ($interfaceDescriptors as $interfaceName => ['wrapperClass' => $wrapperClass]) {
            $wrapperByInterface[$interfaceName] = $wrapperClass;
        }

        $mapping = [];

        // Map compiled router service IDs
        foreach ($routers as $serviceId => ['descriptor' => $descriptor]) {
            $mapping[$serviceId] = $wrapperByInterface[$descriptor->interfaceName];
        }

        // Map any service whose ID matches a known router interface FQCN.
        // This covers cases where a test double or decorator is registered
        // directly under the interface name without the ROUTER tag.
        foreach ($container->getDefinitions() as $defId => $definition) {
            if (! isset($mapping[$defId]) && isset($wrapperByInterface[$defId])) {
                $mapping[$defId] = $wrapperByInterface[$defId];
            }
        }

        // Also map aliases that point to known router services or interface names
        foreach ($container->getAliases() as $aliasId => $alias) {
            $target = (string) $alias;
            if (isset($mapping[$target]) && ! isset($mapping[$aliasId])) {
                $mapping[$aliasId] = $mapping[$target];
            }
        }

        return $mapping;
    }

    private function ensureAsyncQueue(ContainerBuilder $container): void
    {
        if (! $container->has(AsyncQueueInterface::class)) {
            $container->setDefinition(
                AsyncQueueInterface::class,
                (new Definition(InMemoryQueue::class))->setPublic(true),
            );
        }

        if (! $container->has(QueueWorker::class)) {
            $workerDef = new Definition(QueueWorker::class, [
                new Reference(AsyncQueueInterface::class),
                new Reference('service_container'),
            ]);
            $workerDef->setPublic(true);
            $container->setDefinition(QueueWorker::class, $workerDef);
        }
    }

    private function ensureMessageContextPropagator(ContainerBuilder $container): void
    {
        if ($container->has(MessageContextPropagatorInterface::class)) {
            return;
        }

        $container->setDefinition(
            MessageContextPropagatorInterface::class,
            new Definition(MessageContextPropagator::class),
        );
    }

    private function methodReturnsVoid(\ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();

        return $returnType instanceof \ReflectionNamedType && 'void' === $returnType->getName();
    }
}
