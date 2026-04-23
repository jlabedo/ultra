<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent\Protocol;

final class CheckpointMessage extends Message
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $payload = null,
    ) {}

    public function type(): string
    {
        return 'checkpoint';
    }
}
