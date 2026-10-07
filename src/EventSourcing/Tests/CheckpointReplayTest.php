<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Sequence;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\StorageTestCase;

/** @internal */
#[CoversNamespace('Ultra\EventStore')]
#[CoversNamespace('Ultra\EventStore_Postgres')]
final class CheckpointReplayTest extends StorageTestCase
{
    public function test_checkpoints_round_trip_observed_gaps_and_compact_them_when_the_floor_advances(): void
    {
        $checkpoint = new Checkpoint(new Sequence(2), [
            new Sequence(5),
            new Sequence(2),
            new Sequence(4),
            new Sequence(5),
        ]);
        $restored = Checkpoint::deserialize($checkpoint->serialize());
        static::assertEquals($checkpoint, $restored);
        static::assertSame([4, 5], $restored->gapSeqNoValues());
        static::assertTrue($restored->contains(new Sequence(2)));
        static::assertFalse($restored->contains(new Sequence(3)));
        static::assertTrue($restored->contains(new Sequence(4)));
        static::assertFalse($restored->contains(new Sequence(6)));
        static::assertSame($restored, $restored->record(new Sequence(4), inGap: true));
        static::assertSame($restored, $restored->record(new Sequence(1), inGap: false));
        $withGap = $restored->record(new Sequence(7), inGap: true);
        static::assertSame(2, $withGap->gapFloor->value);
        static::assertSame([4, 5, 7], $withGap->gapSeqNoValues());
        $advanced = $withGap->record(new Sequence(6), inGap: false);
        static::assertSame(6, $advanced->gapFloor->value);
        static::assertSame([7], $advanced->gapSeqNoValues());
        static::assertFalse($advanced->isSimplePosition());
        static::assertTrue($advanced->record(new Sequence(8), inGap: false)->isSimplePosition());
        static::assertEquals(Checkpoint::at(new Sequence(8)), $advanced->record(new Sequence(8), inGap: false));
    }

    #[DataProvider('invalidCursors')]
    public function test_invalid_persisted_cursors_are_rejected_with_a_domain_input_error(string $serialized): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Checkpoint::deserialize($serialized);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCursors(): iterable
    {
        yield 'non-integer floor' => ['{"gapFloor":"2","gapSeqNos":[]}'];
        yield 'non-array gaps' => ['{"gapFloor":2,"gapSeqNos":"none"}'];
        yield 'non-integer gap' => ['{"gapFloor":2,"gapSeqNos":["3"]}'];
        yield 'negative position' => ['{"gapFloor":-1,"gapSeqNos":[]}'];
    }

    #[DataProvider('backends')]
    public function test_a_page_size_must_be_positive_even_for_an_empty_query(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $this->expectException(\InvalidArgumentException::class);
        $store->load(StreamQuery::none(), batchSize: 0);
    }

    #[DataProvider('backends')]
    public function test_catch_up_and_bounded_replay_paginate_without_repeating_or_leaking_events(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'c1'));
        $empty = $store->load($query)->checkpoint;
        $store->append([
            self::event('created', ['course' => 'c1'], ['name' => 'first']),
            self::event('renamed', ['course' => 'other'], ['name' => 'other']),
            self::event('renamed', ['course' => 'c1'], ['name' => 'second']),
            self::event('archived', ['course' => 'c1'], ['name' => 'third']),
            self::event('renamed', ['course' => 'c1'], ['name' => 'fourth']),
            self::event('created', ['course' => 'other'], ['name' => 'outside bound stream']),
        ]);
        $saved = $store->load(StreamQuery::all())->checkpoint;
        $store->append([self::event('restored', ['course' => 'c1'], ['name' => 'later'])]);

        $first = $store->load($query, batchSize: 2);
        $second = $store->load($query, from: $first->checkpoint, batchSize: 2);
        static::assertSame(['first', 'second'], array_column(array_column($first->events, 'payload'), 'name'));
        static::assertSame(['third', 'fourth'], array_column(array_column($second->events, 'payload'), 'name'));
        static::assertTrue($first->hasMore);
        static::assertTrue($second->hasMore);
        static::assertFalse($first->checkpoint->contains(new Sequence(4)));
        $last = $store->load($query, from: $second->checkpoint, batchSize: 2);
        static::assertSame(['later'], array_column(array_column($last->events, 'payload'), 'name'));
        static::assertFalse($last->hasMore);
        if ('memory' === $backend) {
            static::assertSame(7, $last->checkpoint->gapFloor->value);
            static::assertTrue($last->checkpoint->isSimplePosition());
        }
        $caughtUp = $store->load($query, from: $last->checkpoint);
        static::assertSame([], $caughtUp->events);
        static::assertFalse($caughtUp->hasMore);
        static::assertEquals($last->checkpoint, $caughtUp->checkpoint);
        $complete = $store->load($query, batchSize: 5);
        static::assertCount(5, $complete->events);
        static::assertFalse($complete->hasMore);

        $replayFirst = $store->load($query, to: $saved, batchSize: 2);
        $replayLast = $store->load($query, from: $replayFirst->checkpoint, to: $saved, batchSize: 2);
        static::assertSame(['first', 'second'], array_column(array_column($replayFirst->events, 'payload'), 'name'));
        static::assertTrue($replayFirst->hasMore);
        static::assertTrue($replayFirst->checkpoint->contains(new Sequence(3)));
        static::assertFalse($replayFirst->checkpoint->contains(new Sequence(4)));
        static::assertSame(['third', 'fourth'], array_column(array_column($replayLast->events, 'payload'), 'name'));
        static::assertFalse($replayLast->hasMore);
        static::assertEquals($saved, $replayLast->checkpoint);
        static::assertSame([], $store->load($query, to: $empty)->events);
        static::assertEquals($empty, $store->load($query, to: $empty)->checkpoint);
        static::assertEquals($saved, $store->load(StreamQuery::none(), from: $empty, to: $saved)->checkpoint);
        static::assertEquals($saved, $store->load(StreamQuery::none(), from: $saved)->checkpoint);
        static::assertFalse($store->load($query, to: $empty)->hasMore);
        static::assertFalse($store->load(StreamQuery::none(), from: $empty, to: $saved)->hasMore);
        static::assertFalse($store->load(StreamQuery::none(), from: $saved)->hasMore);
    }

    #[DataProvider('backends')]
    public function test_catch_up_loads_unseen_lower_sequences_while_replay_keeps_only_the_observed_set(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'c1'));
        $store->append([
            self::event('created', ['course' => 'c1'], ['name' => 'first']),
            self::event('created', ['course' => 'c1'], ['name' => 'late']),
            self::event('created', ['course' => 'c1'], ['name' => 'gap']),
            self::event('created', ['course' => 'c1'], ['name' => 'next']),
        ]);
        $checkpoint = new Checkpoint(new Sequence(1), [new Sequence(3)]);

        static::assertSame(
            ['late', 'next'],
            array_column(array_column($store->load($query, from: $checkpoint)->events, 'payload'), 'name'),
        );
        static::assertSame(
            ['first', 'gap'],
            array_column(array_column($store->load($query, to: $checkpoint)->events, 'payload'), 'name'),
        );
    }
}
