<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Attribute\ProjectionResetter;

final readonly class ProjectionDefinition
{
    /** @param array<string, ProjectionHandlerDefinition> $handlers */
    private function __construct(
        public string $projectionId,
        public string $partitionDimension,
        public ?string $initializerMethod,
        public ?string $resetterMethod,
        public ?string $flushMethod,
        private array $handlers,
    ) {}

    public static function fromClass(string $class): self
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Class %s does not exist.', $class));
        }

        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(Projection::class);

        if ([] === $attributes) {
            throw new \InvalidArgumentException(sprintf(
                'Class %s must be annotated with #[Projection].',
                $reflection->getName(),
            ));
        }

        $attribute = $attributes[0]->newInstance();

        return new self(
            projectionId: $attribute->name,
            partitionDimension: $attribute->partitionBy,
            initializerMethod: self::resolveInitializerMethod($reflection),
            resetterMethod: self::resolveResetterMethod($reflection),
            flushMethod: self::resolveFlushMethod($reflection),
            handlers: self::resolveHandlers($reflection),
        );
    }

    public static function fromProjection(object $projection): self
    {
        return self::fromClass($projection::class);
    }

    /** @return list<string> */
    public function handledEventTypes(): array
    {
        return array_keys($this->handlers);
    }

    public function streamQuery(): StreamQuery
    {
        return StreamQuery::all()->ofTypes(...$this->handledEventTypes());
    }

    public function streamQueryForSegment(int $bucketFrom, int $bucketTo): StreamQuery
    {
        return StreamQuery::forSegment(
            $this->partitionDimension,
            $bucketFrom,
            $bucketTo,
            ...$this->handledEventTypes(),
        );
    }

    public function handles(Event $event): bool
    {
        return isset($this->handlers[$event->eventType]);
    }

    public function bucketFor(Event $event): ?int
    {
        $value = $event->domainIds[$this->partitionDimension] ?? null;
        if (null === $value) {
            return null;
        }

        return BucketHasher::bucketFor($value);
    }

    public function handlerFor(Event|string $event): ?ProjectionHandlerDefinition
    {
        $eventType = $event instanceof Event ? $event->eventType : $event;

        return $this->handlers[$eventType] ?? null;
    }

    /**
     * @return array<string, ProjectionHandlerDefinition>
     */
    private static function resolveHandlers(\ReflectionClass $reflection): array
    {
        $handlers = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ([] === $method->getAttributes(ProjectionHandler::class)) {
                continue;
            }

            $parameters = $method->getParameters();
            if (1 !== count($parameters)) {
                throw new \LogicException(sprintf(
                    '#[ProjectionHandler] method %s::%s must have exactly one parameter.',
                    $reflection->getName(),
                    $method->getName(),
                ));
            }

            $type = $parameters[0]->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new \LogicException(sprintf(
                    '#[ProjectionHandler] method %s::%s must type-hint a specific event class.',
                    $reflection->getName(),
                    $method->getName(),
                ));
            }

            /** @var class-string $eventClass */
            $eventClass = $type->getName();
            $eventType = self::resolveEventTypeName($eventClass, $reflection->getName(), $method->getName());

            if (isset($handlers[$eventType])) {
                throw new \LogicException(sprintf(
                    'Duplicate #[ProjectionHandler] for event type "%s" in %s.',
                    $eventType,
                    $reflection->getName(),
                ));
            }

            $handlers[$eventType] = new ProjectionHandlerDefinition(
                eventType: $eventType,
                eventClass: $eventClass,
                method: $method->getName(),
            );
        }

        return $handlers;
    }

    private static function resolveInitializerMethod(\ReflectionClass $reflection): ?string
    {
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ([] === $method->getAttributes(ProjectionInitializer::class)) {
                continue;
            }

            if (0 !== count($method->getParameters())) {
                throw new \LogicException(sprintf(
                    '#[ProjectionInitializer] method %s::%s must have no parameters.',
                    $reflection->getName(),
                    $method->getName(),
                ));
            }

            return $method->getName();
        }

        return null;
    }

    private static function resolveFlushMethod(\ReflectionClass $reflection): ?string
    {
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ([] === $method->getAttributes(ProjectionFlush::class)) {
                continue;
            }

            if (0 !== count($method->getParameters())) {
                throw new \LogicException(sprintf(
                    '#[ProjectionFlush] method %s::%s must have no parameters.',
                    $reflection->getName(),
                    $method->getName(),
                ));
            }

            return $method->getName();
        }

        return null;
    }

    private static function resolveResetterMethod(\ReflectionClass $reflection): ?string
    {
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ([] === $method->getAttributes(ProjectionResetter::class)) {
                continue;
            }

            if (0 !== count($method->getParameters())) {
                throw new \LogicException(sprintf(
                    '#[ProjectionResetter] method %s::%s must have no parameters.',
                    $reflection->getName(),
                    $method->getName(),
                ));
            }

            return $method->getName();
        }

        return null;
    }

    private static function resolveEventTypeName(string $eventClass, string $projectionClass, string $method): string
    {
        if (!class_exists($eventClass)) {
            throw new \LogicException(sprintf(
                'Event class %s (referenced in %s::%s) does not exist.',
                $eventClass,
                $projectionClass,
                $method,
            ));
        }

        $attributes = new \ReflectionClass($eventClass)->getAttributes(EventAttribute::class);
        if ([] === $attributes) {
            throw new \LogicException(sprintf(
                'Event class %s has no #[Event] attribute (referenced in %s::%s).',
                $eventClass,
                $projectionClass,
                $method,
            ));
        }

        return $attributes[0]->newInstance()->name;
    }
}
