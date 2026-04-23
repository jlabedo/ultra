<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent\Protocol;

final class InitMessage extends Message
{
    public function __construct(
        public readonly string $closure,
    ) {}

    public function type(): string
    {
        return 'init';
    }
}
