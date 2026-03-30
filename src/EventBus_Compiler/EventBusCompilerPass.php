<?php

declare(strict_types=1);

namespace Ultra\EventBus_Compiler;

use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\EventBus\Attribute\EventHandler;
use Ultra\EventBus\EventBusInterface;
use Ultra\MessageHandling\MessageContextPropagator;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling_Compiler\AdviceCollector;
use Ultra\MessageHandling_Compiler\DecoratingDependencyExpressionResolver;
use Ultra\MessageHandling_Compiler\DispatchPlanRenderer;
use Ultra\MessageHandling_Compiler\HandlerDescriptor;
use Ultra\MessageHandling_Compiler\HandlerDescriptorFactory;
use Ultra\MessageHandling_Compiler\HandlerMethodGenerator;
use Ultra\MessageHandling_Compiler\LocatorReferenceCollector;

final class EventBusCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $outputDir,
        private readonly string $generatedClass = 'CompiledEventBus',
        private readonly string $handlerTag = 'event_bus.handler',
        private readonly string $aspectTag = 'event_bus.aspect',
        private readonly string $eventTag = 'event_bus.event',
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $this->ensureMessageContextPropagator($container);

        $adviceCollector = new AdviceCollector($this->aspectTag);
        $descriptorFactory = new HandlerDescriptorFactory();
        $locatorReferences = new LocatorReferenceCollector();

        /** @var array<string, list<array{handler: HandlerDescriptor, method: \ReflectionMethod}>> $subscriptions */
        $subscriptions = [];

        foreach (array_keys($container->findTaggedServiceIds($this->handlerTag)) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;

            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attributes = $method->getAttributes(EventHandler::class);
                if ([] === $attributes) {
                    continue;
                }

                $params = $method->getParameters();
                if ([] === $params) {
                    throw new \LogicException(sprintf(
                        'Event handler "%s::%s" must declare the event as its first parameter.',
                        $class,
                        $method->getName(),
                    ));
                }

                $firstType = $params[0]->getType();
                if (! $firstType instanceof \ReflectionNamedType || $firstType->isBuiltin()) {
                    throw new \LogicException(sprintf(
                        'Event handler "%s::%s" must type-hint a non-builtin event class, interface, or abstract class as its first parameter.',
                        $class,
                        $method->getName(),
                    ));
                }

                $attribute = $attributes[0]->newInstance();
                $subscriptionType = $firstType->getName();
                $handler = $descriptorFactory->create(
                    $serviceId,
                    $class,
                    $method,
                    priority: $attribute->priority,
                );

                $subscriptions[$subscriptionType][] = [
                    'handler' => $handler,
                    'method' => $method,
                ];
                $locatorReferences->addService($serviceId);
            }
        }

        /** @var array<class-string, list<HandlerDescriptor>> $routes */
        $routes = [];
        foreach ($container->findTaggedResourceIds($this->eventTag) as $serviceId => $_) {
            $eventClass = $container->getDefinition($serviceId)->getClass();
            $matchedHandlers = [];
            foreach ($subscriptions as $subscriptionType => $entries) {
                if (! is_a($eventClass, $subscriptionType, true)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    $advices = $adviceCollector->collect($entry['method'], $container);
                    $handler = $entry['handler']->withAdvices($advices);
                    $matchedHandlers[] = $handler;
                    $locatorReferences->addHandlerDependencies($handler, $advices);
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

            $routes[$eventClass] = $matchedHandlers;
        }

        $filePath = rtrim($this->outputDir, '/').'/'.$this->generatedClass.'.php';
        new EventBusGenerator(
            new HandlerMethodGenerator(
                renderer: new DispatchPlanRenderer([
                    new DecoratingDependencyExpressionResolver(
                        EventBusInterface::class,
                        'Ultra\EventBus\ContextPropagatingEventBus',
                    ),
                    new DecoratingDependencyExpressionResolver(
                        'Ultra\Mediator\MediatorInterface',
                        'Ultra\Mediator\ContextPropagatingMediator',
                    ),
                ]),
            ),
        )->generate($this->generatedClass, $routes, $filePath);

        $eventBusDef = new Definition($this->generatedClass, [
            new ServiceLocatorArgument($this->serviceLocatorReferences($locatorReferences->all())),
            new Reference(MessageContextPropagatorInterface::class),
        ]);
        $eventBusDef->setFile($filePath)->setPublic(true);
        $container->setDefinition(EventBusInterface::class, $eventBusDef);
    }

    /**
     * @param list<string> $serviceIds
     *
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
            new Definition(MessageContextPropagator::class),
        );
    }
}
