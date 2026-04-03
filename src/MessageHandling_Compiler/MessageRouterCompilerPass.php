<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MessageHandling\Attribute\Advice;
use Ultra\MessageHandling\Attribute\MultiDispatch;
use Ultra\MessageHandling\Attribute\SingleDispatch;
use Ultra\MessageHandling\MessageContextPropagator;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling\TagNames;
use Ultra\MessageHandling_Compiler\Handler\AdviceCollector;
use Ultra\MessageHandling_Compiler\Handler\AdviceDefinition;
use Ultra\MessageHandling_Compiler\Handler\AdviceType;
use Ultra\MessageHandling_Compiler\Handler\DispatchPlanRenderer;
use Ultra\MessageHandling_Compiler\Handler\HandlerDescriptor;
use Ultra\MessageHandling_Compiler\Handler\HandlerDescriptorFactory;
use Ultra\MessageHandling_Compiler\Handler\HandlerMethodGenerator;
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
 *  2. Discover handlers: find all services tagged $handlerTag. Reflect on public methods.
 *     Build a flat pool of (serviceId, class, ReflectionMethod) tuples.
 *
 *  3. For each router, match handlers via the descriptor's matcher closure:
 *     - SingleDispatch: enforce one handler per message class; @self for self-handlers
 *     - MultiDispatch: collect subscriptions, resolve via is_a() against known message classes,
 *       sort by priority
 *
 *  4. Build ContextualRouterDependencyResolver from the discovered routers, mapping each
 *     router service ID (and its aliases) to its generated contextual wrapper class name.
 *
 *  5. For each router, collect aspects (global $aspectTag), generate class, register service.
 */
final readonly class MessageRouterCompilerPass implements CompilerPassInterface
{
    public function __construct(private string $outputDir) {}

    public function process(ContainerBuilder $container): void
    {
        $this->ensureMessageContextPropagator($container);

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
                    $descriptor = $this->buildRouterDescriptor($interfaceName);
                    if (null === $descriptor) {
                        continue;
                    }

                    $shortName = substr($interfaceName, strrpos($interfaceName, '\\') + 1);

                    $interfaceDescriptors[$interfaceName] = [
                        'descriptor' => $descriptor,
                        'baseName' => 'Compiled'.$shortName,
                        'wrapperClass' => MessageRouterGenerator::wrapperClassName($interfaceName, $this->outputDir),
                    ];
                }

                $routerOutputDir = rtrim($this->outputDir, '/').'/'.substr($interfaceName, strrpos($interfaceName, '\\') + 1);
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

        // ── Step 2: Discover handlers ──────────────────────────────────────────
        /** @var list<array{serviceId: string, class: string, method: \ReflectionMethod}> $handlerPool */
        $handlerPool = [];

        foreach (array_keys($container->findTaggedServiceIds(TagNames::HANDLER)) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;

            if (! class_exists($class)) {
                continue;
            }

            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $handlerPool[] = [
                    'serviceId' => $serviceId,
                    'class' => $class,
                    'method' => $method,
                ];
            }
        }

        // ── Step 4 (early): Build contextual wrapper mapping for all routers ─────
        $wrapperClassByServiceId = $this->buildWrapperClassMapping($routers, $interfaceDescriptors, $container);
        $resolver = new ContextualRouterDependencyResolver($wrapperClassByServiceId);

        // ── Steps 3 + 5: Match handlers, generate, register ───────────────────
        $adviceDefinitions = $this->collectAdviceDefinitions($container);
        $adviceCollector = new AdviceCollector($adviceDefinitions);
        $descriptorFactory = new HandlerDescriptorFactory();
        $methodGenerator = new HandlerMethodGenerator(
            renderer: new DispatchPlanRenderer([$resolver]),
        );
        $generator = new MessageRouterGenerator($methodGenerator);

        // Generate shared contextual wrapper classes file
        $wrappersFilePath = $this->generateWrappersFile($generator, $interfaceDescriptors);

        foreach ($routers as $serviceId => ['descriptor' => $descriptor, 'baseName' => $baseName, 'outputDir' => $routerOutputDir]) {
            // Skip compilation for synthetic services (e.g. test doubles).
            // Their wrapper is still generated via the shared wrappers file,
            // but the service itself is not replaced.
            $existingDef = $container->hasDefinition($serviceId) ? $container->getDefinition($serviceId) : null;
            if (null !== $existingDef && $existingDef->isSynthetic()) {
                continue;
            }

            $locatorReferences = new LocatorReferenceCollector();

            $routes = $descriptor->isSingleDispatch
                ? $this->matchSingleDispatch($descriptor, $handlerPool, $descriptorFactory, $locatorReferences, $container)
                : $this->matchMultiDispatch($descriptor, $handlerPool, $descriptorFactory, $locatorReferences, $container);

            // Collect aspects and attach to handlers
            $routes = $this->attachAspects($routes, $adviceCollector, $locatorReferences, $container, $descriptor->isSingleDispatch);

            ['className' => $generatedClass, 'filePath' => $filePath] = $generator->generate(
                $baseName, $descriptor, $routes, $routerOutputDir, $wrappersFilePath,
            );

            $routerDef = new Definition($generatedClass, [
                new ServiceLocatorArgument($this->buildLocatorReferences($locatorReferences->all())),
                new Reference(MessageContextPropagatorInterface::class),
            ]);
            $routerDef->setFile($filePath)->setPublic(true);
            $container->setDefinition($serviceId, $routerDef);
        }
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
                    $adviceDefinitions[] = new AdviceDefinition(
                        AdviceType::fromAttribute($advice),
                        $advice->priority,
                        $class,
                        $methodRef->getName(),
                        $aspectServiceId,
                        $advice->matcher,
                    );
                }
            }
        }

        return $adviceDefinitions;
    }

    /**
     * Reflect on an interface to find #[SingleDispatch] or #[MultiDispatch] on a method.
     */
    private function buildRouterDescriptor(string $interfaceName): ?RouterDescriptor
    {
        $reflection = new \ReflectionClass($interfaceName);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $singleAttrs = $method->getAttributes(SingleDispatch::class);
            $multiAttrs = $method->getAttributes(MultiDispatch::class);

            if ([] !== $singleAttrs) {
                /** @var SingleDispatch $attr */
                $attr = $singleAttrs[0]->newInstance();
                $params = $method->getParameters();
                $firstParam = $params[0] ?? null;

                return new RouterDescriptor(
                    interfaceName: $interfaceName,
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
                    interfaceName: $interfaceName,
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
     * Returns array<class-string, list<HandlerDescriptor>> where each list has exactly one element.
     *
     * @param list<array{serviceId: string, class: string, method: \ReflectionMethod}> $handlerPool
     *
     * @return array<class-string, list<HandlerDescriptor>>
     */
    private function matchSingleDispatch(
        RouterDescriptor $descriptor,
        array $handlerPool,
        HandlerDescriptorFactory $descriptorFactory,
        LocatorReferenceCollector $locatorReferences,
        ContainerBuilder $container,
    ): array {
        /** @var array<class-string, HandlerDescriptor> $handlers */
        $handlers = [];

        foreach ($handlerPool as ['serviceId' => $serviceId, 'class' => $class, 'method' => $method]) {
            $match = ($descriptor->matcher)($method);
            if (null === $match) {
                continue;
            }

            if ($match->isSelfHandler) {
                // Self-handler: the message class handles itself, no message param
                $messageClass = $class;

                if (isset($handlers[$messageClass])) {
                    throw new \LogicException(sprintf(
                        'Duplicate handler for message "%s": already registered by service "%s", cannot also register "%s" as a self-handler.',
                        $messageClass,
                        $handlers[$messageClass][0]->serviceId,
                        $serviceId,
                    ));
                }

                $handlers[$messageClass] = $descriptorFactory->create('@self', $class, $method, skipFirstParameter: false);
                // Message class is NOT added to the locator
            } else {
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
                    throw new \LogicException(sprintf(
                        'Duplicate handler for message "%s": already registered by service "%s", cannot also register "%s".',
                        $messageClass,
                        $handlers[$messageClass]->serviceId,
                        $serviceId,
                    ));
                }

                $handlers[$messageClass] = $descriptorFactory->create($serviceId, $class, $method);
                $locatorReferences->addService($serviceId);
            }
        }

        // Wrap each HandlerDescriptor in a list<> for unified format
        $routes = [];
        foreach ($handlers as $messageClass => $handler) {
            $routes[$messageClass] = [$handler];
        }

        return $routes;
    }

    /**
     * Match handlers for MultiDispatch routers using is_a() polymorphic routing.
     *
     * @param list<array{serviceId: string, class: string, method: \ReflectionMethod}> $handlerPool
     *
     * @return array<class-string, list<HandlerDescriptor>>
     */
    private function matchMultiDispatch(
        RouterDescriptor $descriptor,
        array $handlerPool,
        HandlerDescriptorFactory $descriptorFactory,
        LocatorReferenceCollector $locatorReferences,
        ContainerBuilder $container,
    ): array {
        /** @var array<string, list<array{handler: HandlerDescriptor, method: \ReflectionMethod}>> $subscriptions */
        $subscriptions = [];

        foreach ($handlerPool as ['serviceId' => $serviceId, 'class' => $class, 'method' => $method]) {
            $match = ($descriptor->matcher)($method);
            if (null === $match) {
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

            $subscriptionType = $firstType->getName();
            $handler = $descriptorFactory->create($serviceId, $class, $method, priority: $match->priority);
            $subscriptions[$subscriptionType][] = [
                'handler' => $handler,
                'method' => $method,
            ];
            $locatorReferences->addService($serviceId);
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
                    $matchedHandlers[] = $entry['handler'];
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

                return $left->method <=> $right->method;
            });

            $routes[$messageClass] = $matchedHandlers;
        }

        return $routes;
    }

    /**
     * Attach aspects to all handlers in the routes map.
     *
     * @param array<class-string, list<HandlerDescriptor>> $routes
     *
     * @return array<class-string, list<HandlerDescriptor>>
     */
    private function attachAspects(
        array $routes,
        AdviceCollector $adviceCollector,
        LocatorReferenceCollector $locatorReferences,
        ContainerBuilder $container,
        bool $isSingleDispatch,
    ): array {
        $result = [];

        foreach ($routes as $messageClass => $handlers) {
            $withAdvices = [];
            foreach ($handlers as $handler) {
                // For single dispatch we need to reflect on the handler's method
                $reflection = null;
                if ('@self' !== $handler->serviceId) {
                    $reflection = new \ReflectionClass($handler->className ?: $handler->serviceId)
                        ->getMethod($handler->method)
                    ;
                } else {
                    // Self handler: method is on the message class itself
                    $reflection = new \ReflectionClass($messageClass)->getMethod($handler->method);
                }

                $advices = $adviceCollector->collect($reflection, $container);
                $handlerWithAdvices = $handler->withAdvices($advices);
                $locatorReferences->addHandlerDependencies($handler, $advices);
                $withAdvices[] = $handlerWithAdvices;
            }
            $result[$messageClass] = $withAdvices;
        }

        return $result;
    }

    /**
     * Generate a shared PHP file containing all contextual wrapper classes.
     *
     * Each compiled router file will require_once this file so the wrappers
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

        $filePath = rtrim($this->outputDir, '/').'/_contextual_wrappers.php';
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

    /**
     * @param list<string> $serviceIds
     *
     * @return array<string, Reference>
     */
    private function buildLocatorReferences(array $serviceIds): array
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
            new Definition(MessageContextPropagator::class),
        );
    }

    private function methodReturnsVoid(\ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();

        return $returnType instanceof \ReflectionNamedType && 'void' === $returnType->getName();
    }
}
