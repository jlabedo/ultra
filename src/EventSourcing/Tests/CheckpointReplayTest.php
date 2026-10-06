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
        $checkpoint = new Checkpoint(new Sequence(2), [new Sequence(5), new Sequence(2), new Sequence(4), new Sequence(5)]);
        $restored = Checkpoint::deserialize($checkpoint->serialize());
        self::assertEquals($checkpoint, $restored);
        self::assertSame([4, 5], $restored->gapSeqNoValues());
        self::assertTrue($restored->contains(new Sequence(2)));
        self::assertFalse($restored->contains(new Sequence(3)));
        self::assertTrue($restored->contains(new Sequence(4)));
        self::assertFalse($restored->contains(new Sequence(6)));
        self::assertSame($restored, $restored->record(new Sequence(4), inGap: true));
        self::assertSame($restored, $restored->record(new Sequence(1), inGap: false));
        $withGap = $restored->record(new Sequence(7), inGap: true);
        self::assertSame(2, $withGap->gapFloor->value);
        self::assertSame([4, 5, 7], $withGap->gapSeqNoValues());
        $advanced = $withGap->record(new Sequence(6), inGap: false);
        self::assertSame(6, $advanced->gapFloor->value);
        self::assertSame([7], $advanced->gapSeqNoValues());
        self::assertFalse($advanced->isSimplePosition());
        self::assertTrue($advanced->record(new Sequence(8), inGap: false)->isSimplePosition());
        self::assertEquals(Checkpoint::at(new Sequence(8)), $advanced->record(new Sequence(8), inGap: false));
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
        self::assertSame(['first', 'second'], array_column(array_column($first->events, 'payload'), 'name'));
        self::assertSame(['third', 'fourth'], array_column(array_column($second->events, 'payload'), 'name'));
        $last = $store->load($query, from: $second->checkpoint, batchSize: 2);
        self::assertSame(['later'], array_column(array_column($last->events, 'payload'), 'name'));
        if ('memory' === $backend) {
            self::assertSame(7, $last->checkpoint->gapFloor->value);
            self::assertTrue($last->checkpoint->isSimplePosition());
        }
        $caughtUp = $store->load($query, from: $last->checkpoint);
        self::assertSame([], $caughtUp->events);
        self::assertEquals($last->checkpoint, $caughtUp->checkpoint);

        $replayFirst = $store->load($query, to: $saved, batchSize: 2);
        $replayLast = $store->load($query, from: $replayFirst->checkpoint, to: $saved, batchSize: 2);
        self::assertSame(['first', 'second'], array_column(array_column($replayFirst->events, 'payload'), 'name'));
        self::assertTrue($replayFirst->checkpoint->contains(new Sequence(3)));
        self::assertFalse($replayFirst->checkpoint->contains(new Sequence(4)));
        self::assertSame(['third', 'fourth'], array_column(array_column($replayLast->events, 'payload'), 'name'));
        self::assertEquals($saved, $replayLast->checkpoint);
        self::assertSame([], $store->load($query, to: $empty)->events);
        self::assertEquals($empty, $store->load($query, to: $empty)->checkpoint);
        self::assertEquals($saved, $store->load(StreamQuery::none(), from: $empty, to: $saved)->checkpoint);
        self::assertEquals($saved, $store->load(StreamQuery::none(), from: $saved)->checkpoint);
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

        self::assertSame(['late', 'next'], array_column(array_column($store->load($query, from: $checkpoint)->events, 'payload'), 'name'));
        self::assertSame(['first', 'gap'], array_column(array_column($store->load($query, to: $checkpoint)->events, 'payload'), 'name'));
    }
}
