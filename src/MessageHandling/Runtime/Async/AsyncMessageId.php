<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

final readonly class AsyncMessageId
{
    public function __construct(public string $value)
    {
        if ('' === $value) {
            throw new \InvalidArgumentException('Async message id must be a non-empty string.');
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }
}
