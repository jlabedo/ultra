<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Psr\Clock\ClockInterface;
use Ultra\Projection\Coordination\ProjectionCoordinatorLeaseRepositoryInterface;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final class InMemoryProjectionCoordinatorLeaseRepository implements ProjectionCoordinatorLeaseRepositoryInterface
{
    private readonly ClockInterface $clock;

    /** @var array{workerId: null|string, leaseExpiresAt: null|DatePoint, wakeEnqueuedUntil: null|DatePoint} */
    private array $row = ['workerId' => null, 'leaseExpiresAt' => null, 'wakeEnqueuedUntil' => null];

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function acquire(string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): bool
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
        $row['leaseExpiresAt'] = $this->now()->add($leaseDuration);
        $row['wakeEnqueuedUntil'] = null;
        $this->row = $row;

        return true;
    }

    public function renew(string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): void
    {
        $row = $this->row;
        if ($row['workerId'] !== $workerId) {
            return;
        }

        $row['leaseExpiresAt'] = $this->now()->add($leaseDuration);
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

    public function markWakeEnqueued(Duration $ttl = new Duration(seconds: 30)): bool
    {
        $row = $this->row;
        if ($this->isLeaseExpired($row)) {
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
        }

        $now = $this->now();
        if (null !== $row['workerId']) {
            $this->row = $row;

            return false;
        }

        if (null !== $row['wakeEnqueuedUntil'] && $row['wakeEnqueuedUntil'] >= $now) {
            $this->row = $row;

            return false;
        }

        $row['wakeEnqueuedUntil'] = $now->add($ttl);
        $this->row = $row;

        return true;
    }

    /** @param array{workerId: null|string, leaseExpiresAt: null|int, wakeEnqueuedUntil: null|int} $row */
    private function isLeaseExpired(array $row): bool
    {
        return null !== $row['leaseExpiresAt'] && $row['leaseExpiresAt'] < $this->now();
    }

    private function now(): DatePoint
    {
        return DatePoint::createFromInterface($this->clock->now());
    }
}
