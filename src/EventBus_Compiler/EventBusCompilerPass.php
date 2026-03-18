<?php

declare(strict_types=1);

namespace Ultra\EventBus_Compiler;

use Composer\Autoload\ClassLoader;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\EventBus\Attribute\EventHandler;
use Ultra\EventBus\CompiledEventRegistry;
use Ultra\EventBus\EventBusInterface;
use Ultra\EventBus\EventRegistryInterface;
use Ultra\MessageHandling\Attribute\Event;
use Ultra\MessageHandling_Compiler\AdviceCollector;
use Ultra\MessageHandling_Compiler\HandlerDescriptor;
use Ultra\MessageHandling_Compiler\HandlerDescriptorFactory;
use Ultra\MessageHandling_Compiler\LocatorReferenceCollector;

final class EventBusCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $outputDir,
        private readonly string $generatedClass = 'CompiledEventBus',
        private readonly string $handlerTag = 'event_bus.handler',
        private readonly string $aspectTag = 'event_bus.aspect',
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $eventMap = $this->discoverEvents();
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
        foreach ($eventMap as $eventName => $eventClass) {
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

        $this->registerEventRegistry($container, $eventMap);
        $this->registerNormalizableEvents($container, array_values($eventMap));

        $filePath = rtrim($this->outputDir, '/').'/'.$this->generatedClass.'.php';
        (new EventBusGenerator())->generate($this->generatedClass, $routes, $filePath);

        $eventBusDef = new Definition($this->generatedClass, [new ServiceLocatorArgument($this->serviceLocatorReferences($locatorReferences->all()))]);
        $eventBusDef->setFile($filePath)->setPublic(true);
        $container->setDefinition(EventBusInterface::class, $eventBusDef);
    }

    /**
     * @return array<string, class-string>
     */
    private function discoverEvents(): array
    {
        $events = [];

        foreach ($this->discoverProjectClasses() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }

            $attributes = $ref->getAttributes(Event::class);
            if ([] === $attributes) {
                continue;
            }

            /** @var Event $attribute */
            $attribute = $attributes[0]->newInstance();
            if (isset($events[$attribute->name]) && $events[$attribute->name] !== $class) {
                throw new \LogicException(sprintf(
                    'Duplicate #[Event] name "%s": "%s" and "%s".',
                    $attribute->name,
                    $events[$attribute->name],
                    $class,
                ));
            }

            $events[$attribute->name] = $class;
        }

        ksort($events);

        return $events;
    }

    /**
     * @return list<class-string>
     */
    private function discoverProjectClasses(): array
    {
        $projectRoot = getcwd();
        if (false === $projectRoot) {
            return [];
        }

        $classes = [];
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $prefix => $dirs) {
                foreach ($dirs as $dir) {
                    if (! is_dir($dir)) {
                        continue;
                    }
                    $realDir = realpath($dir);
                    if (false === $realDir) {
                        continue;
                    }
                    if (! str_starts_with($realDir, $projectRoot) || str_contains($realDir, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                        continue;
                    }

                    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($realDir, \FilesystemIterator::SKIP_DOTS));
                    foreach ($iterator as $file) {
                        if ('php' !== $file->getExtension()) {
                            continue;
                        }
                        if (str_ends_with($file->getFilename(), 'Test.php')) {
                            continue;
                        }

                        $relativePath = substr($file->getPathname(), strlen($realDir) + 1);
                        if (false === $relativePath) {
                            continue;
                        }

                        $class = $prefix.str_replace(
                            [DIRECTORY_SEPARATOR, '.php'],
                            ['\\', ''],
                            $relativePath,
                        );
                        $classes[$class] = $class;
                    }
                }
            }
        }

        ksort($classes);

        return array_values($classes);
    }

    /**
     * @param array<string, class-string> $eventMap
     */
    private function registerEventRegistry(ContainerBuilder $container, array $eventMap): void
    {
        $definition = new Definition(CompiledEventRegistry::class, [$eventMap]);
        $definition->setPublic(true);
        $container->setDefinition(EventRegistryInterface::class, $definition);
    }

    /**
     * @param list<class-string> $eventClasses
     */
    private function registerNormalizableEvents(ContainerBuilder $container, array $eventClasses): void
    {
        $existing = $container->hasParameter('ultra_mapper.normalizable_classes')
            ? $container->getParameter('ultra_mapper.normalizable_classes')
            : [];

        if (! is_array($existing)) {
            $existing = [];
        }

        $merged = array_values(array_unique(array_merge($existing, $eventClasses)));
        sort($merged);
        $container->setParameter('ultra_mapper.normalizable_classes', $merged);
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
}
