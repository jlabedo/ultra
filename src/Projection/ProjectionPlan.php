<?php

declare(strict_types=1);

namespace Ultra\Projection;

readonly class ProjectionPlan
{
    /**
     * @var list<string>
     */
    public array $eventTypes;

    public DeliveryMode $delivery;

    /**
     * @param list<string> $eventTypes
     */
    public function __construct(
        public Projection $projection,
        array $eventTypes,
        ?DeliveryMode $delivery = null,
    ) {
        $normalizedEventTypes = [];
        foreach ($eventTypes as $eventType) {
            if (! is_string($eventType) || '' === $eventType) {
                throw new \InvalidArgumentException('Projection event types must be non-empty strings.');
            }
            $normalizedEventTypes[$eventType] = true;
        }

        if ([] === $normalizedEventTypes) {
            throw new \InvalidArgumentException('Projection plans must declare at least one event type.');
        }

        $normalizedEventTypes = array_keys($normalizedEventTypes);
        sort($normalizedEventTypes);

        $this->eventTypes = $normalizedEventTypes;
        $this->delivery = $delivery ?? DeliveryMode::direct();
    }

    public function name(): string
    {
        return $this->projection->name();
    }

    public function version(): int
    {
        return $this->projection->version();
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
