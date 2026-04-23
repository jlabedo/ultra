<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent;

final class Checkpoint
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $payload = null,
    ) {}
}
