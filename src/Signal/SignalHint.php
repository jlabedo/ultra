<?php

declare(strict_types=1);

namespace Ultra\Signal;

final readonly class SignalHint
{
    public function __construct(
        public int $id,
        public string $queueName,
        public int $bucket,
        public ?string $signalKey,
    ) {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Signal hint id must be positive.');
        }

        if ('' === $queueName) {
            throw new \InvalidArgumentException('Signal hint queue name must be non-empty.');
        }

        if ('' === $signalKey) {
            throw new \InvalidArgumentException('Signal hint key must be null or a non-empty string.');
        }
    }
}
