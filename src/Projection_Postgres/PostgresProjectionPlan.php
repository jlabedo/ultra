<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Ultra\EventStore\StoredEvent;
use Ultra\Projection\ProjectionPlan;

readonly class PostgresProjectionPlan
{
    public ProjectionPlan $projection;

    public DeliveryMode $delivery;

    public function __construct(
        object $projection,
        ?DeliveryMode $delivery = null,
    ) {
        $this->projection = $projection instanceof ProjectionPlan ? $projection : new ProjectionPlan($projection);
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

    /**
     * @return list<string>
     */
    public function eventTypes(): array
    {
        return $this->projection->eventTypes;
    }

    public function apply(StoredEvent $event): void
    {
        $this->projection->apply($event);
    }

    public function hasProjectionInitializer(): bool
    {
        return $this->projection->hasProjectionInitializer();
    }

    public function initializeProjection(): void
    {
        $this->projection->initializeProjection();
    }

    public function hasPartitionInitializer(): bool
    {
        return $this->projection->hasPartitionInitializer();
    }

    public function initializePartition(string $partitionKey): void
    {
        $this->projection->initializePartition($partitionKey);
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
