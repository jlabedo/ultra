<?php

declare(strict_types=1);

namespace Ultra\Testing;

use Doctrine\DBAL\ParameterType;

/** One completed driver call; its duration excludes connection setup and result fetching. */
final readonly class SqlTraceEntry
{
    /**
     * @param array<array-key, mixed> $parameters
     * @param array<array-key, ParameterType> $types
     */
    public function __construct(
        public string $phase,
        public string $operation,
        public ?string $sql,
        public array $parameters,
        public array $types,
        public float $durationMilliseconds,
        public ?string $errorClass,
        public ?string $errorMessage,
    ) {}
}
