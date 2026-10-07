<?php

declare(strict_types=1);

namespace Ultra\Coordination\Lease;

use Ultra\Time\Duration;

/**
 * A singleton-worker lease with a debounced wake signal.
 *
 * At most one worker holds the lease for a given `$coordinatorKey` at a time;
 * the lease expires unless renewed, so a dead holder is eventually superseded.
 * `markWakeEnqueued()` coalesces wake requests: it returns true at most once
 * per TTL window (and never while a coordinator is already live), so callers
 * can fan many "work arrived" notifications into a single wake.
 *
 * The coordinator key lets independent subsystems each run their own singleton
 * (e.g. one per projection runtime, or one per derived read model).
 */
interface CoordinatorLease
{
    public function acquire(
        string $coordinatorKey,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): bool;

    public function renew(
        string $coordinatorKey,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): void;

    public function release(string $coordinatorKey, string $workerId): void;

    public function hasLiveCoordinator(string $coordinatorKey): bool;

    /**
     * Reserve a wake within the TTL window. Returns true if the caller should
     * actually enqueue a wake (i.e. none is pending and no coordinator is
     * live), false if a wake is already in flight or unnecessary.
     */
    public function markWakeEnqueued(string $coordinatorKey, Duration $ttl = new Duration(seconds: 30)): bool;
}
