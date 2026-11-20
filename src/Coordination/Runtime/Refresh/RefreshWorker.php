<?php

declare(strict_types=1);

namespace Ultra\Coordination\Refresh;

use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Coordination\Tracking\PendingKey;

final readonly class RefreshWorker
{
    public function __construct(
        private DirtyKeyTracker $dirty,
        private RefreshPublication $publication,
    ) {}

    /**
     * Prepare work outside the write transaction. Stop on an empty queue, a publication conflict or the step limit.
     * The tracker and publication adapter must use the same transaction resource for writes and acknowledgements.
     *
     * @param callable(PendingKey): RefreshStep $prepare
     * @return int Number of steps published by this call, including partial steps.
     */
    public function run(string $channel, callable $prepare, int $maxSteps = 100): int
    {
        if ($maxSteps < 1) {
            throw new \InvalidArgumentException('The refresh step limit must be positive.');
        }

        for ($published = 0; $published < $maxSteps; ++$published) {
            $revision = $this->publication->revision($channel);
            $pending = $this->dirty->pendingForChannel($channel, 1)[0] ?? null;
            if (null === $pending) {
                break;
            }

            $step = $prepare($pending);
            if ($step->acknowledge < 0 || $step->acknowledge > $pending->counter) {
                throw new \InvalidArgumentException('A refresh step may only acknowledge its observed counter.');
            }

            if (!$this->publication->publish($channel, $revision, function () use ($pending, $step): void {
                ($step->write)();
                if ($step->acknowledge > 0) {
                    $this->dirty->drainSeen($pending->channel, [$pending->key => $step->acknowledge]);
                }
            })) {
                break;
            }
        }

        return $published;
    }
}
