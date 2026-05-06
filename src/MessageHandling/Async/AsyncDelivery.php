<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

final readonly class AsyncDelivery
{
    public function __construct(
        public AsyncMessageId $messageId,
        public AsyncEnvelope $envelope,
        public string $leaseId,
        public int $attempts,
        public \DateTimeImmutable $leasedUntil,
    ) {
        if ('' === $leaseId) {
            throw new \InvalidArgumentException('Async delivery lease id must be a non-empty string.');
        }
    }
}
