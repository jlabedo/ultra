<?php

declare(strict_types=1);

namespace Ultra\Coordination\InMemory;

use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Time\ClockInterface;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final class InMemoryCoordinatorLease implements CoordinatorLease
{
    /** @var array<string, array{workerId: null|string, leaseExpiresAt: null|DatePoint, wakeEnqueuedUntil: null|DatePoint}> */
    private array $rows = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function acquire(
        string $coordinatorKey,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): bool {
        $row = $this->row($coordinatorKey);
        if ($this->isLeaseExpired($row)) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
        }

        if (null !== $row['workerId']) {
            $this->rows[$coordinatorKey] = $row;

            return false;
        }

        $row['workerId'] = $workerId;
        $row['leaseExpiresAt'] = $this->clock->now()->add($leaseDuration);
        $row['wakeEnqueuedUntil'] = null;
        $this->rows[$coordinatorKey] = $row;

        return true;
    }

    public function renew(
        string $coordinatorKey,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): void {
        $row = $this->row($coordinatorKey);
        if ($row['workerId'] !== $workerId) {
            return;
        }

        $row['leaseExpiresAt'] = $this->clock->now()->add($leaseDuration);
        $this->rows[$coordinatorKey] = $row;
    }

    public function release(string $coordinatorKey, string $workerId): void
    {
        $row = $this->row($coordinatorKey);
        if ($row['workerId'] !== $workerId) {
            return;
        }

        $row['workerId'] = null;
        $row['leaseExpiresAt'] = null;
        $this->rows[$coordinatorKey] = $row;
    }

    public function hasLiveCoordinator(string $coordinatorKey): bool
    {
        $row = $this->row($coordinatorKey);
        if ($this->isLeaseExpired($row)) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
            $this->rows[$coordinatorKey] = $row;
        }

        return null !== $row['workerId'];
    }

    public function markWakeEnqueued(string $coordinatorKey, Duration $ttl = new Duration(seconds: 30)): bool
    {
        $row = $this->row($coordinatorKey);
        if ($this->isLeaseExpired($row)) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
        }

        $now = $this->clock->now();
        if (null !== $row['workerId']) {
            $this->rows[$coordinatorKey] = $row;

            return false;
        }

        if (null !== $row['wakeEnqueuedUntil'] && $row['wakeEnqueuedUntil'] >= $now) {
            $this->rows[$coordinatorKey] = $row;

            return false;
        }

        $row['wakeEnqueuedUntil'] = $now->add($ttl);
        $this->rows[$coordinatorKey] = $row;

        return true;
    }

    /** @return array{workerId: null|string, leaseExpiresAt: null|DatePoint, wakeEnqueuedUntil: null|DatePoint} */
    private function row(string $coordinatorKey): array
    {
        return (
            $this->rows[$coordinatorKey] ?? ['workerId' => null, 'leaseExpiresAt' => null, 'wakeEnqueuedUntil' => null]
        );
    }

    /** @param array{workerId: null|string, leaseExpiresAt: null|DatePoint, wakeEnqueuedUntil: null|DatePoint} $row */
    private function isLeaseExpired(array $row): bool
    {
        return null !== $row['leaseExpiresAt'] && $row['leaseExpiresAt'] < $this->clock->now();
    }
}
