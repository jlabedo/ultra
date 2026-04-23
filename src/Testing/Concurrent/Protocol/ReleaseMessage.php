<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent\Protocol;

final class ReleaseMessage extends Message
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $inject = null,
    ) {}

    public function type(): string
    {
        return 'release';
    }
}
