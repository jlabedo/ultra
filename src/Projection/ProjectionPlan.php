<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\StoredEvent;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;

readonly class ProjectionPlan
{
    /**
     * @var list<string>
     */
    public array $eventTypes;

    /**
     * @var array<string, string>
     */
    private array $handlerMethods;

    public DeliveryMode $delivery;

    public function __construct(
        public object $projection,
        ?DeliveryMode $delivery = null,
    ) {
        $reflection = new \ReflectionObject($projection);
        $attributes = $reflection->getAttributes(Projection::class);
        if ([] === $attributes) {
            throw new \LogicException(sprintf(
                'Projection class %s must declare #[%s].',
                $projection::class,
                Projection::class,
            ));
        }

        /** @var Projection $metadata */
        $metadata = $attributes[0]->newInstance();
        $this->name = $metadata->name;
        $this->version = $metadata->version;

        $handlerMethods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $handlerAttributes = $method->getAttributes(ProjectionHandler::class);
            if ([] === $handlerAttributes) {
                continue;
            }

            /** @var ProjectionHandler $handler */
            $handler = $handlerAttributes[0]->newInstance();
            if (isset($handlerMethods[$handler->eventType])) {
                throw new \LogicException(sprintf(
                    'Projection %s declares multiple handlers for event type "%s".',
                    $projection::class,
                    $handler->eventType,
                ));
            }

            $parameters = $method->getParameters();
            if (1 !== count($parameters)) {
                throw new \LogicException(sprintf(
                    'Projection handler %s::%s must declare exactly one StoredEvent parameter.',
                    $projection::class,
                    $method->getName(),
                ));
            }

            $type = $parameters[0]->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin() || StoredEvent::class !== $type->getName()) {
                throw new \LogicException(sprintf(
                    'Projection handler %s::%s must type-hint %s.',
                    $projection::class,
                    $method->getName(),
                    StoredEvent::class,
                ));
            }

            $handlerMethods[$handler->eventType] = $method->getName();
        }

        if ([] === $handlerMethods) {
            throw new \LogicException(sprintf(
                'Projection class %s must declare at least one #[%s] method.',
                $projection::class,
                ProjectionHandler::class,
            ));
        }

        $normalizedEventTypes = array_keys($handlerMethods);
        sort($normalizedEventTypes);

        $this->eventTypes = $normalizedEventTypes;
        $this->handlerMethods = $handlerMethods;
        $this->delivery = $delivery ?? DeliveryMode::direct();
    }

    public string $name;

    public int $version;

    public function name(): string
    {
        return $this->name;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function apply(StoredEvent $event): void
    {
        $method = $this->handlerMethods[$event->eventType] ?? null;
        if (null === $method) {
            return;
        }

        $this->projection->{$method}($event);
    }

    public function isPartitioned(): bool
    {
        return $this->delivery instanceof PartitionedDelivery;
    }

    public function partitionStrategy(): ?PartitionStrategy
    {
        if (! $this->delivery instanceof PartitionedDelivery) {
            return null;
        }

        return $this->delivery->strategy;
    }
}
