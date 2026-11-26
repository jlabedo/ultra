<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

/** @template-covariant TEnvelope of object */
interface DeadLetterQueue
{
    public function countFailed(): int;

    /**
     * List dead letters in ascending order of failure time and message identifier.
     *
     * @return list<DeadLetter<TEnvelope>>
     */
    public function listFailed(int $limit = 100, int $offset = 0): array;

    /** @return DeadLetter<TEnvelope>|null */
    public function inspectFailed(AsyncMessageId $messageId): ?DeadLetter;

    /** Return true if a dead letter was put back in the queue. */
    public function replayFailed(AsyncMessageId $messageId): bool;
}
