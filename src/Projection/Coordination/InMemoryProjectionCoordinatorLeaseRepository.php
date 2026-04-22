<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

final class InMemoryProjectionCoordinatorLeaseRepository implements ProjectionCoordinatorLeaseRepositoryInterface
{
    /** @var array<string, array{workerId: string|null, leaseExpiresAt: int|null, wakeEnqueuedUntil: int|null}> */
    private array $rows = [];

    public function acquire(string $projectionId, string $workerId, int $leaseDurationSeconds = 60): bool
    {
        $row = $this->row($projectionId);
        if ($this->isLeaseExpired($row)) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
        }

        if (null !== $row['workerId']) {
            $this->rows[$projectionId] = $row;
            return false;
        }

        $row['workerId'] = $workerId;
        $row['leaseExpiresAt'] = time() + $leaseDurationSeconds;
        $row['wakeEnqueuedUntil'] = null;
        $this->rows[$projectionId] = $row;

        return true;
    }

    public function renew(string $projectionId, string $workerId, int $leaseDurationSeconds = 60): void
    {
        $row = $this->row($projectionId);
        if ($row['workerId'] !== $workerId) {
            return;
        }

        $row['leaseExpiresAt'] = time() + $leaseDurationSeconds;
        $this->rows[$projectionId] = $row;
    }

    public function release(string $projectionId, string $workerId): void
    {
        $row = $this->row($projectionId);
        if ($row['workerId'] !== $workerId) {
            return;
        }

        $row['workerId'] = null;
        $row['leaseExpiresAt'] = null;
        $this->rows[$projectionId] = $row;
    }

    public function hasLiveCoordinator(string $projectionId): bool
    {
        $row = $this->row($projectionId);
        if ($this->isLeaseExpired($row)) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
            $this->rows[$projectionId] = $row;
        }

        return null !== $row['workerId'];
    }

    public function markWakeEnqueued(string $projectionId, int $ttlSeconds = 30): bool
    {
        $row = $this->row($projectionId);
        if ($this->isLeaseExpired($row)) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
        }

        $now = time();
        if (null !== $row['workerId']) {
            $this->rows[$projectionId] = $row;
            return false;
        }

        if (null !== $row['wakeEnqueuedUntil'] && $row['wakeEnqueuedUntil'] >= $now) {
            $this->rows[$projectionId] = $row;
            return false;
        }

        $row['wakeEnqueuedUntil'] = $now + $ttlSeconds;
        $this->rows[$projectionId] = $row;

        return true;
    }

    /** @param array{workerId: string|null, leaseExpiresAt: int|null, wakeEnqueuedUntil: int|null} $row */
    private function isLeaseExpired(array $row): bool
    {
        return null !== $row['leaseExpiresAt'] && $row['leaseExpiresAt'] < time();
    }

    /** @return array{workerId: string|null, leaseExpiresAt: int|null, wakeEnqueuedUntil: int|null} */
    private function row(string $projectionId): array
    {
        return $this->rows[$projectionId] ?? ['workerId' => null, 'leaseExpiresAt' => null, 'wakeEnqueuedUntil' => null];
    }
}
