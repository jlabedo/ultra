<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\EventStore\Checkpoint;
use Ultra\Projection\ProjectionSegment;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;

final class InMemoryProjectionSegmentRepository implements ProjectionSegmentRepositoryInterface
{
    /** @var array<string, array<int, array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: string|null, checkpoint: Checkpoint, workerId: string|null, leaseExpiresAt: int|null}>> */
    private array $segments = [];

    public function ensureInitialized(string $projectionId): bool
    {
        if (isset($this->segments[$projectionId]) && [] !== $this->segments[$projectionId]) {
            return false;
        }

        $this->segments[$projectionId] = [
            0 => [
                'bucketFrom' => 0,
                'bucketTo' => 255,
                'status' => 'available',
                'pendingOperation' => null,
                'checkpoint' => Checkpoint::beginning(),
                'workerId' => null,
                'leaseExpiresAt' => null,
            ],
        ];

        return true;
    }

    public function claimSegment(string $projectionId, string $workerId, int $leaseDurationSeconds = 60): ?ProjectionSegment
    {
        $this->recoverExpiredLeases($projectionId);

        foreach (array_keys($this->segments[$projectionId] ?? []) as $bucketFrom) {
            $row = $this->segments[$projectionId][$bucketFrom];
            if (!in_array($row['status'], ['available', 'draining'], true)) {
                continue;
            }

            $row['status'] = $row['pendingOperation'] ? 'draining' : 'active';
            $row['workerId'] = $workerId;
            $row['leaseExpiresAt'] = time() + $leaseDurationSeconds;
            $this->segments[$projectionId][$bucketFrom] = $row;

            return $this->toSegment($projectionId, $row);
        }

        return null;
    }

    public function claimSegmentForBucket(string $projectionId, int $bucket, string $workerId, int $leaseDurationSeconds = 60): ?ProjectionSegment
    {
        $this->recoverExpiredLeases($projectionId);

        foreach (array_keys($this->segments[$projectionId] ?? []) as $bucketFrom) {
            $row = $this->segments[$projectionId][$bucketFrom];
            if (!in_array($row['status'], ['available', 'draining'], true)) {
                continue;
            }

            if ($bucket < $row['bucketFrom'] || $bucket > $row['bucketTo']) {
                continue;
            }

            $row['status'] = $row['pendingOperation'] ? 'draining' : 'active';
            $row['workerId'] = $workerId;
            $row['leaseExpiresAt'] = time() + $leaseDurationSeconds;
            $this->segments[$projectionId][$bucketFrom] = $row;

            return $this->toSegment($projectionId, $row);
        }

        return null;
    }

    public function readCurrentState(string $projectionId, int $bucketFrom): ProjectionSegment
    {
        $row = $this->segments[$projectionId][$bucketFrom] ?? null;
        if (null === $row) {
            throw new \LogicException(sprintf(
                'Segment [%d] not found for projection "%s".',
                $bucketFrom,
                $projectionId,
            ));
        }

        return $this->toSegment($projectionId, $row);
    }

    public function completeSegment(ProjectionSegment $segment, Checkpoint $checkpoint): void
    {
        $row = $this->segments[$segment->projectionId][$segment->bucketFrom] ?? null;
        if (null === $row) {
            return;
        }

        $row['status'] = null === $row['pendingOperation'] ? 'available' : 'drained';
        $row['workerId'] = null;
        $row['leaseExpiresAt'] = null;
        $row['checkpoint'] = $checkpoint;
        $this->segments[$segment->projectionId][$segment->bucketFrom] = $row;
    }

    public function abandonSegment(ProjectionSegment $segment): void
    {
        $row = $this->segments[$segment->projectionId][$segment->bucketFrom] ?? null;
        if (null === $row) {
            return;
        }

        $row['status'] = null === $row['pendingOperation'] ? 'available' : 'draining';
        $row['workerId'] = null;
        $row['leaseExpiresAt'] = null;
        $this->segments[$segment->projectionId][$segment->bucketFrom] = $row;
    }

    public function renewLease(ProjectionSegment $segment, string $workerId, int $leaseDurationSeconds = 60): void
    {
        $row = $this->segments[$segment->projectionId][$segment->bucketFrom] ?? null;
        if (null === $row || $row['workerId'] !== $workerId || $row['status'] !== 'active') {
            return;
        }

        $row['leaseExpiresAt'] = time() + $leaseDurationSeconds;
        $this->segments[$segment->projectionId][$segment->bucketFrom] = $row;
    }

    public function recoverExpiredLeases(string $projectionId): int
    {
        $recovered = 0;

        foreach (array_keys($this->segments[$projectionId] ?? []) as $bucketFrom) {
            $row = $this->segments[$projectionId][$bucketFrom];
            if ($row['status'] !== 'active') {
                continue;
            }

            if (null === $row['leaseExpiresAt'] || $row['leaseExpiresAt'] >= time()) {
                continue;
            }

            $row['status'] = null === $row['pendingOperation'] ? 'available' : 'draining';
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
            $this->segments[$projectionId][$bucketFrom] = $row;
            ++$recovered;
        }

        return $recovered;
    }

    public function splitSegment(string $projectionId, int $bucketFrom): void
    {
        $row = $this->segments[$projectionId][$bucketFrom] ?? null;
        if (null === $row) {
            throw new \InvalidArgumentException(sprintf(
                'Segment [%d] not found for projection "%s".',
                $bucketFrom,
                $projectionId,
            ));
        }

        if ($row['status'] === 'active') {
            $row['status'] = 'draining';
            $row['pendingOperation'] = 'split';
            $this->segments[$projectionId][$bucketFrom] = $row;
            return;
        }

        if ($row['status'] === 'available') {
            $row['status'] = 'drained';
            $row['pendingOperation'] = 'split';
            $this->segments[$projectionId][$bucketFrom] = $row;
            return;
        }

        if ($row['status'] !== 'drained') {
            throw new \LogicException(sprintf(
                'Cannot split segment [%d-%d] of projection "%s": status is "%s".',
                $row['bucketFrom'],
                $row['bucketTo'],
                $projectionId,
                $row['status'],
            ));
        }
    }

    public function finalizeSplit(ProjectionSegment $segment, Checkpoint $checkpoint): void
    {
        $rangeSize = $segment->bucketTo - $segment->bucketFrom + 1;
        if ($rangeSize < 2) {
            throw new \LogicException(sprintf(
                'Segment [%d-%d] is at minimum granularity and cannot be split.',
                $segment->bucketFrom,
                $segment->bucketTo,
            ));
        }

        $mid = $segment->bucketFrom + intdiv($rangeSize, 2);
        unset($this->segments[$segment->projectionId][$segment->bucketFrom]);

        $this->segments[$segment->projectionId][$segment->bucketFrom] = [
            'bucketFrom' => $segment->bucketFrom,
            'bucketTo' => $mid - 1,
            'status' => 'available',
            'pendingOperation' => null,
            'checkpoint' => $checkpoint,
            'workerId' => null,
            'leaseExpiresAt' => null,
        ];
        $this->segments[$segment->projectionId][$mid] = [
            'bucketFrom' => $mid,
            'bucketTo' => $segment->bucketTo,
            'status' => 'available',
            'pendingOperation' => null,
            'checkpoint' => $checkpoint,
            'workerId' => null,
            'leaseExpiresAt' => null,
        ];
        ksort($this->segments[$segment->projectionId]);
    }

    public function requestMerge(string $projectionId, int $bucketFrom, int $bucketTo): void
    {
        $rangeSize = $bucketTo - $bucketFrom + 1;
        $mid = $bucketFrom + intdiv($rangeSize, 2);

        foreach ([$bucketFrom, $mid] as $from) {
            $row = $this->segments[$projectionId][$from] ?? null;
            if (null === $row || !in_array($row['status'], ['available', 'active'], true)) {
                throw new \LogicException(sprintf(
                    'Cannot merge [%d-%d] for projection "%s".',
                    $bucketFrom,
                    $bucketTo,
                    $projectionId,
                ));
            }

            $row['status'] = $row['status'] === 'active' ? 'draining' : 'drained';
            $row['pendingOperation'] = 'merge';
            $this->segments[$projectionId][$from] = $row;
        }
    }

    public function reconcilePendingOperations(string $projectionId): int
    {
        $reconciled = 0;

        do {
            $progressed = false;

            foreach (array_keys($this->segments[$projectionId] ?? []) as $bucketFrom) {
                $row = $this->segments[$projectionId][$bucketFrom];

                if ('split' === $row['pendingOperation'] && 'drained' === $row['status']) {
                    $this->finalizeSplit($this->toSegment($projectionId, $row), $row['checkpoint']);
                    ++$reconciled;
                    $progressed = true;
                    break;
                }

                if ('merge' !== $row['pendingOperation'] || 'drained' !== $row['status']) {
                    continue;
                }

                $before = count($this->segments[$projectionId]);
                $this->finalizeMerge($this->toSegment($projectionId, $row), $row['checkpoint']);
                if (count($this->segments[$projectionId]) !== $before) {
                    ++$reconciled;
                    $progressed = true;
                    break;
                }
            }
        } while ($progressed);

        return $reconciled;
    }

    public function finalizeMerge(ProjectionSegment $segment, Checkpoint $checkpoint): void
    {
        [$parentFrom, $parentTo, $siblingFrom] = $this->resolveMergeSibling($segment);
        $row = $this->segments[$segment->projectionId][$segment->bucketFrom] ?? null;
        if (null === $row) {
            return;
        }

        $row['status'] = 'drained';
        $row['checkpoint'] = $checkpoint;
        $row['workerId'] = null;
        $row['leaseExpiresAt'] = null;
        $this->segments[$segment->projectionId][$segment->bucketFrom] = $row;

        $sibling = $this->segments[$segment->projectionId][$siblingFrom] ?? null;
        if (null === $sibling || $sibling['status'] !== 'drained') {
            return;
        }

        $parentCheckpoint = $checkpoint->gapFloor->isAtOrBefore($sibling['checkpoint']->gapFloor)
            ? $checkpoint
            : $sibling['checkpoint'];

        unset($this->segments[$segment->projectionId][$segment->bucketFrom], $this->segments[$segment->projectionId][$siblingFrom]);
        $this->segments[$segment->projectionId][$parentFrom] = [
            'bucketFrom' => $parentFrom,
            'bucketTo' => $parentTo,
            'status' => 'available',
            'pendingOperation' => null,
            'checkpoint' => $parentCheckpoint,
            'workerId' => null,
            'leaseExpiresAt' => null,
        ];
        ksort($this->segments[$segment->projectionId]);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function resolveMergeSibling(ProjectionSegment $segment): array
    {
        $rangeSize = $segment->bucketTo - $segment->bucketFrom + 1;
        $parentRangeSize = $rangeSize * 2;

        if ($segment->bucketFrom % $parentRangeSize === 0) {
            $parentFrom = $segment->bucketFrom;
            $parentTo = $segment->bucketFrom + $parentRangeSize - 1;
            $siblingFrom = $segment->bucketTo + 1;
        } else {
            $parentFrom = $segment->bucketFrom - $rangeSize;
            $parentTo = $segment->bucketTo;
            $siblingFrom = $parentFrom;
        }

        return [$parentFrom, $parentTo, $siblingFrom];
    }

    /**
     * @param array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: string|null, checkpoint: Checkpoint, workerId: string|null, leaseExpiresAt: int|null} $row
     */
    private function toSegment(string $projectionId, array $row): ProjectionSegment
    {
        return new ProjectionSegment(
            $projectionId,
            $row['bucketFrom'],
            $row['bucketTo'],
            $row['status'],
            $row['pendingOperation'],
            $row['checkpoint'],
            false,
        );
    }
}
