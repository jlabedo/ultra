<?php

declare(strict_types=1);

namespace Ultra\Projection\Signal;

use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionSegment;
use Ultra\Projection\ProjectionSegmentStoreInterface;
use Ultra\Signal\SignalHandler;
use Ultra\Signal\SignalHint;
use Ultra\Signal\SignalStore;
use Ultra\Time\Duration;

final readonly class ProjectionSignalHandler implements SignalHandler
{
    public function __construct(
        private ProjectionRuntime $runtime,
        private ProjectionRunner $runner,
        private ProjectionSegmentStoreInterface $segmentStore,
        private SignalStore $signalStore,
        private string $workerId,
        private int $batchSize = 100,
        private Duration $suppressionTtl = new Duration(seconds: 60),
    ) {
        if ('' === $workerId) {
            throw new \InvalidArgumentException('Projection signal worker id must be non-empty.');
        }

        if ($batchSize <= 0) {
            throw new \InvalidArgumentException('Projection signal batch size must be positive.');
        }
    }

    public function queueName(): string
    {
        return $this->runtime->projectionId();
    }

    public function handleSignalHints(array $hints): void
    {
        if ([] === $hints) {
            return;
        }

        $this->runner->ensureInitialized($this->runtime);

        foreach ($this->currentSegmentsCovering($hints) as $segment) {
            do {
                $result = $this->runner->runSignaledOnce(
                    $this->runtime,
                    $this->signalStore,
                    $segment->projectionId,
                    $segment->bucketFrom,
                    $this->workerId,
                    $this->batchSize,
                    $this->suppressionTtl,
                );
            } while ($result->claimedSegment && !$result->caughtUp);
        }
    }

    /**
     * @param list<SignalHint> $hints
     *
     * @return list<ProjectionSegment>
     */
    private function currentSegmentsCovering(array $hints): array
    {
        $segments = $this->segmentStore->listSegments($this->runtime->projectionId());
        $selected = [];

        foreach ($hints as $hint) {
            if ($hint->queueName !== $this->runtime->projectionId()) {
                continue;
            }

            foreach ($segments as $segment) {
                if ($hint->bucket < $segment->bucketFrom || $hint->bucket > $segment->bucketTo) {
                    continue;
                }

                $selected[$segment->bucketFrom] = $segment;
                break;
            }
        }

        ksort($selected, SORT_NUMERIC);

        return array_values($selected);
    }
}
