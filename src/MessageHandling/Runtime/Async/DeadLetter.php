<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\Time\DatePoint;

/** @template-covariant TEnvelope of object */
final readonly class DeadLetter
{
    /** @param TEnvelope $envelope */
    public function __construct(
        public AsyncMessageId $messageId,
        public object $envelope,
        public int $attempts,
        public DatePoint $failedAt,
        public ?string $failureClass,
        public string $failureMessage,
    ) {}
}
