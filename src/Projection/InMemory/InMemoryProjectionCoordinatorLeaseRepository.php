<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Projection\Coordination\ProjectionCoordinatorLeaseRepositoryInterface;

final class InMemoryProjectionCoordinatorLeaseRepository implements ProjectionCoordinatorLeaseRepositoryInterface
{
    /** @var array{workerId: string|null, leaseExpiresAt: int|null, wakeEnqueuedUntil: int|null} */
    private array $row = ['workerId' => null, 'leaseExpiresAt' => null, 'wakeEnqueuedUntil' => null];

    public function acquire(string $workerId, int $leaseDurationSeconds = 60): bool
    {
        $row = $this->row;
        if ($this->isLeaseExpired($row)) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
        }

        if (null !== $row['workerId']) {
            $this->row = $row;
            return false;
        }

        $row['workerId'] = $workerId;
        $row['leaseExpiresAt'] = time() + $leaseDurationSeconds;
        $row['wakeEnqueuedUntil'] = null;
        $this->row = $row;

        return true;
    }

    public function renew(string $workerId, int $leaseDurationSeconds = 60): void
    {
        $row = $this->row;
        if ($row['workerId'] !== $workerId) {
            return;
        }

        $row['leaseExpiresAt'] = time() + $leaseDurationSeconds;
        $this->row = $row;
    }

    public function release(string $workerId): void
    {
        $row = $this->row;
        if ($row['workerId'] !== $workerId) {
            return;
        }

        $row['workerId'] = null;
        $row['leaseExpiresAt'] = null;
        $this->row = $row;
    }

    public function hasLiveCoordinator(): bool
    {
        $row = $this->row;
        if ($this->isLeaseExpired($row)) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
            $this->row = $row;
        }

        return null !== $row['workerId'];
    }

    public function markWakeEnqueued(int $ttlSeconds = 30): bool
    {
        $row = $this->row;
        if ($this->isLeaseExpired($row)) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
        }

        $now = time();
        if (null !== $row['workerId']) {
            $this->row = $row;
            return false;
        }

        if (null !== $row['wakeEnqueuedUntil'] && $row['wakeEnqueuedUntil'] >= $now) {
            $this->row = $row;
            return false;
        }

        $row['wakeEnqueuedUntil'] = $now + $ttlSeconds;
        $this->row = $row;

        return true;
    }

    /** @param array{workerId: string|null, leaseExpiresAt: int|null, wakeEnqueuedUntil: int|null} $row */
    private function isLeaseExpired(array $row): bool
    {
        return null !== $row['leaseExpiresAt'] && $row['leaseExpiresAt'] < time();
    }

}
