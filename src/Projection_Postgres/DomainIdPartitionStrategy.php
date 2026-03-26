<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Ultra\EventStore\StoredEvent;

final class DomainIdPartitionStrategy extends PartitionStrategy
{
    /**
     * @var list<string>
     */
    private array $dimensions;

    /**
     * @param list<string> $dimensions
     */
    public function __construct(array $dimensions)
    {
        $normalized = [];
        foreach ($dimensions as $dimension) {
            if (! is_string($dimension) || '' === $dimension) {
                throw new \InvalidArgumentException('Partition dimensions must be non-empty strings.');
            }

            $normalized[$dimension] = true;
        }

        $this->dimensions = array_keys($normalized);
        sort($this->dimensions);

        if ([] === $this->dimensions) {
            throw new \InvalidArgumentException('At least one partition dimension is required.');
        }
    }

    public function dimensions(): array
    {
        return $this->dimensions;
    }

    public function keyFor(StoredEvent $event): string
    {
        $values = [];
        foreach ($this->dimensions as $dimension) {
            $value = $event->domainIds[$dimension] ?? null;
            if (! is_string($value) || '' === $value) {
                throw new \RuntimeException(sprintf(
                    'Stored event %d (%s) does not contain partition dimension "%s".',
                    $event->eventId,
                    $event->eventType,
                    $dimension,
                ));
            }

            $values[$dimension] = $value;
        }

        return json_encode($values, JSON_THROW_ON_ERROR);
    }
}
