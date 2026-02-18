<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class StreamId
{
    public function __construct(
        public string $dimension,
        public string $value,
        public string $eventType,
    ) {
    }

    public function toString(): string
    {
        return $this->dimension . "\0" . $this->value . "\0" . $this->eventType;
    }

    public static function fromEvent(string $eventType, array $domainIds): array
    {
        $streamIds = [];
        foreach ($domainIds as $dimension => $value) {
            $streamIds[] = new self($dimension, $value, $eventType);
        }

        return $streamIds;
    }
}
