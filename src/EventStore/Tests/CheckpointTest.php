<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Sequence;

/**
 * @internal
 */
#[CoversClass(Checkpoint::class)]
#[CoversClass(Sequence::class)]
final class CheckpointTest extends TestCase
{
    public function test_checkpoint_round_trips_through_json(): void
    {
        $checkpoint = new Checkpoint(new Sequence(10), [new Sequence(12), new Sequence(11)]);

        $restored = Checkpoint::deserialize($checkpoint->serialize());

        self::assertSame(10, $restored->gapFloor->value);
        self::assertSame([11, 12], $restored->gapSeqNoValues());
    }

    public function test_stable_sequence_advances_the_floor_and_compacts_old_gap_entries(): void
    {
        $checkpoint = new Checkpoint(new Sequence(2), [new Sequence(5)]);

        $advanced = $checkpoint->record(new Sequence(6), inGap: false);

        self::assertSame(6, $advanced->gapFloor->value);
        self::assertSame([], $advanced->gapSeqNoValues());
    }

    public function test_gap_sequence_is_recorded_above_the_floor(): void
    {
        $checkpoint = Checkpoint::at(new Sequence(2))
            ->record(new Sequence(5), inGap: true);

        self::assertSame(2, $checkpoint->gapFloor->value);
        self::assertSame([5], $checkpoint->gapSeqNoValues());
    }
}
