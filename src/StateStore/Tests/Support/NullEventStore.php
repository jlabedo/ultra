<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests\Support;

use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;

final class NullEventStore implements EventStore
{
    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        return new LoadResult([], $to ?? $from ?? Checkpoint::beginning(), $query);
    }

    public function append(array $events, AppendCondition ...$appendConditions): void {}
}
