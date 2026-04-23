<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent\Protocol;

final class ExceptionMessage extends Message
{
    public function __construct(
        public readonly string $class,
        public readonly string $message,
        public readonly string $trace,
        public readonly ?string $serialized = null,
    ) {}

    public function type(): string
    {
        return 'exception';
    }
}
