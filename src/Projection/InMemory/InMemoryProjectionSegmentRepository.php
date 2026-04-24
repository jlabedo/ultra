<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\EventStore\Checkpoint;
use Ultra\Projection\ProjectionSegment;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\ProjectionWorkAssignment;
use Ultra\Time\ClockInterface;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final class InMemoryProjectionSegmentRepository implements ProjectionSegmentRepositoryInterface
{
    /** @var array<string, array<int, array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: null|string, checkpoint: Checkpoint, workerId: null|string, leaseExpiresAt: null|int, assignmentId: null|string}>> */
    private array $segments = [];

    public function __construct(private ClockInterface $clock = new SystemClock())
    {
    }

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
                'assignmentId' => null,
            ],
        ];

        return true;
    }

    public function assignSegment(string $projectionId, int $bucketFrom, string $workerId, ?Duration $leaseDuration = null): ?ProjectionWorkAssignment
    {
        $leaseDuration ??= Duration::seconds(60);
        $row = $this->segments[$projectionId][$bucketFrom] ?? null;
        if (null === $row || 'available' !== $row['status'] || null !== $row['pendingOperation']) {
            return null;
        }

        $assignmentId = bin2hex(random_bytes(16));
        $row['status'] = 'assigned';
        $row['workerId'] = $workerId;
        $row['leaseExpiresAt'] = $this->clock->now()->add($leaseDuration);
        $row['assignmentId'] = $assignmentId;
        $this->segments[$projectionId][$bucketFrom] = $row;

        return new ProjectionWorkAssignment($assignmentId, $projectionId, $bucketFrom, $workerId);
    }

    public function claimAssignedSegment(ProjectionWorkAssignment $assignment, ?Duration $leaseDuration = null): ?ProjectionSegment
    {
        $leaseDuration ??= Duration::seconds(60);
        $row = $this->segments[$assignment->projectionId][$assignment->bucketFrom] ?? null;
        if (
            null === $row
            || 'assigned' !== $row['status']
            || $row['workerId'] !== $assignment->workerId
            || $row['assignmentId'] !== $assignment->assignmentId
            || (null !== $row['leaseExpiresAt'] && $row['leaseExpiresAt'] < $this->clock->now())
        ) {
            return null;
        }

        $row['status'] = 'active';
        $row['leaseExpiresAt'] = $this->clock->now()->add($leaseDuration);
        $this->segments[$assignment->projectionId][$assignment->bucketFrom] = $row;

        return $this->toSegment($assignment->projectionId, $row);
    }

    public function completeAssignedBatch(
        ProjectionSegment $segment,
        Checkpoint $checkpoint,
        string $workerId,
        bool $caughtUp,
        ?Duration $leaseDuration = null,
    ): void {
        $leaseDuration ??= Duration::seconds(60);
        $row = $this->segments[$segment->projectionId][$segment->bucketFrom] ?? null;
        if (null === $row || !in_array($row['status'], ['active', 'draining'], true) || $row['workerId'] !== $workerId) {
            return;
        }

        $row['checkpoint'] = $checkpoint;
        if ($caughtUp) {
            $row['status'] = null === $row['pendingOperation'] ? 'available' : 'drained';
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
            $row['assignmentId'] = null;
        } else {
            $row['status'] = null === $row['pendingOperation'] ? 'assigned' : 'draining';
            $row['leaseExpiresAt'] = $this->clock->now()->add($leaseDuration);
        }

        $this->segments[$segment->projectionId][$segment->bucketFrom] = $row;
    }

    public function abandonAssignedSegment(ProjectionSegment $segment, string $workerId): void
    {
        $row = $this->segments[$segment->projectionId][$segment->bucketFrom] ?? null;
        if (null === $row || $row['workerId'] !== $workerId || !in_array($row['status'], ['assigned', 'active'], true)) {
            return;
        }

        $row['status'] = null === $row['pendingOperation'] ? 'available' : 'draining';
        $row['workerId'] = null;
        $row['leaseExpiresAt'] = null;
        $row['assignmentId'] = null;
        $this->segments[$segment->projectionId][$segment->bucketFrom] = $row;
    }

    public function recoverExpiredAssignments(string $projectionId): int
    {
        $recovered = 0;

        foreach (array_keys($this->segments[$projectionId] ?? []) as $bucketFrom) {
            $row = $this->segments[$projectionId][$bucketFrom];
            if (!in_array($row['status'], ['active', 'assigned'], true)) {
                continue;
            }

            if (null === $row['leaseExpiresAt'] || $row['leaseExpiresAt'] >= $this->clock->now()) {
                continue;
            }

            $row['status'] = null === $row['pendingOperation'] ? 'available' : 'draining';
            $row['workerId'] = null;
            $row['leaseExpiresAt'] = null;
            $row['assignmentId'] = null;
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

        if ('active' === $row['status']) {
            $row['status'] = 'draining';
            $row['pendingOperation'] = 'split';
            $this->segments[$projectionId][$bucketFrom] = $row;

            return;
        }

        if ('available' === $row['status']) {
            $row['status'] = 'drained';
            $row['pendingOperation'] = 'split';
            $this->segments[$projectionId][$bucketFrom] = $row;

            return;
        }

        if ('drained' !== $row['status']) {
            throw new \LogicException(sprintf(
                'Cannot split segment [%d-%d] of projection "%s": status is "%s".',
                $row['bucketFrom'],
                $row['bucketTo'],
                $projectionId,
                $row['status'],
            ));
        }
    }

    public function listSegments(string $projectionId): array
    {
        $rows = $this->segments[$projectionId] ?? [];
        ksort($rows);

        return array_map(static fn (array $row): array => [
            'bucketFrom' => $row['bucketFrom'],
            'bucketTo' => $row['bucketTo'],
            'status' => $row['status'],
            'pendingOperation' => $row['pendingOperation'],
        ], array_values($rows));
    }

    private function finalizeSplit(ProjectionSegment $segment, Checkpoint $checkpoint): void
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
            'assignmentId' => null,
        ];
        $this->segments[$segment->projectionId][$mid] = [
            'bucketFrom' => $mid,
            'bucketTo' => $segment->bucketTo,
            'status' => 'available',
            'pendingOperation' => null,
            'checkpoint' => $checkpoint,
            'workerId' => null,
            'leaseExpiresAt' => null,
            'assignmentId' => null,
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

            $row['status'] = 'active' === $row['status'] ? 'draining' : 'drained';
            $row['pendingOperation'] = 'merge';
            $this->segments[$projectionId][$from] = $row;
        }
    }

    public function availableSegments(string $projectionId): array
    {
        return array_values(array_filter(
            $this->listSegments($projectionId),
            static fn (array $segment): bool => 'available' === $segment['status'] && null === $segment['pendingOperation'],
        ));
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

    private function finalizeMerge(ProjectionSegment $segment, Checkpoint $checkpoint): void
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
        $row['assignmentId'] = null;
        $this->segments[$segment->projectionId][$segment->bucketFrom] = $row;

        $sibling = $this->segments[$segment->projectionId][$siblingFrom] ?? null;
        if (null === $sibling || 'drained' !== $sibling['status']) {
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
            'assignmentId' => null,
        ];
        ksort($this->segments[$segment->projectionId]);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function resolveMergeSibling(ProjectionSegment $segment): array
    {
        $rangeSize = $segment->bucketTo - $segment->bucketFrom + 1;
        $parentRangeSize = $rangeSize * 2;

        if (0 === $segment->bucketFrom % $parentRangeSize) {
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
     * @param array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: null|string, checkpoint: Checkpoint, workerId: null|string, leaseExpiresAt: null|int, assignmentId: null|string} $row
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
