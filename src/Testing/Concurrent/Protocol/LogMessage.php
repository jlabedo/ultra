<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent\Protocol;

final class LogMessage extends Message
{
    public function __construct(
        public readonly string $level,
        public readonly string $message,
    ) {}

    public function type(): string
    {
        return 'log';
    }
}
