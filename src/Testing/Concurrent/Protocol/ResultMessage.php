<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent\Protocol;

final class ResultMessage extends Message
{
    public function __construct(
        public readonly string $value,
    ) {}

    public function type(): string
    {
        return 'result';
    }
}
