<?php

declare(strict_types=1);

namespace Ultra\Signal;

use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

interface SignalStore
{
    public function signal(
        string $queueName,
        int $bucket = 0,
        ?string $signalKey = null,
        ?DatePoint $visibleAt = null,
    ): void;

    /**
     * Claims signal hints for short-lived delivery to handlers.
     *
     * This is not a domain lease.
     *
     * @param list<string> $queueNames
     *
     * @return list<SignalHint>
     */
    public function claimHints(
        array $queueNames,
        string $owner,
        int $limit,
        Duration $deliveryTimeout,
    ): array;

    public function captureVisibilitySnapshot(): VisibilitySnapshot;

    public function ackVisibleInSnapshot(
        string $queueName,
        BucketRange $bucketRange,
        VisibilitySnapshot $snapshot,
        ?int $limit = null,
    ): int;

    public function suppressDispatch(
        string $queueName,
        BucketRange $bucketRange,
        string $owner,
        DatePoint $suppressUntil,
        ?string $domainLeaseRef = null,
    ): SuppressionHandle;

    public function extendSuppression(SuppressionHandle $handle, DatePoint $suppressUntil): void;

    public function releaseSuppression(SuppressionHandle $handle): void;

    public function cleanupExpired(): void;
}
