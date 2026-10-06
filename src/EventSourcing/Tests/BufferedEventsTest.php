<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\RecordingEventStore;
use Ultra\EventStore\Tests\Support\StorageTestCase;

/** @internal */
#[CoversNamespace('Ultra\EventStore')]
#[CoversNamespace('Ultra\EventStore_Postgres')]
final class BufferedEventsTest extends StorageTestCase
{
    public function test_reads_overlay_matching_pending_events_while_checkpoints_and_replay_stay_persisted(): void
    {
        $store = $this->store();
        $uow = new EventStoreUnitOfWork($store);
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'c1'))->ofTypes('created');
        $store->append([self::event('created', ['course' => 'c1'], ['name' => 'persisted'])]);
        $persisted = $store->load($query);
        $uow->append([
            self::event('created', ['course' => 'other'], ['name' => 'other course']),
            self::event('renamed', ['course' => 'c1'], ['name' => 'other type']),
            self::event('created', ['course' => 'c1'], ['name' => 'pending']),
            self::event('created', ['course' => 'c1'], ['name' => 'next pending']),
        ]);

        $visible = $uow->load($query);
        self::assertSame(['persisted', 'pending', 'next pending'], array_column(array_column($visible->events, 'payload'), 'name'));
        self::assertSame(['persisted'], array_column(array_column($store->load($query)->events, 'payload'), 'name'));
        self::assertEquals($persisted->checkpoint, $visible->checkpoint);
        self::assertEquals($persisted->appendCondition(), $visible->appendCondition());
        self::assertSame(['pending', 'next pending'], array_column(array_column($uow->load($query, from: $persisted->checkpoint)->events, 'payload'), 'name'));
        self::assertSame(['persisted'], array_column(array_column($uow->load($query, to: $persisted->checkpoint)->events, 'payload'), 'name'));
        self::assertSame([], $uow->load(StreamQuery::none())->events);
    }

    #[DataProvider('backends')]
    public function test_dependent_buffered_appends_flush_once_in_order_with_their_original_conditions(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $recording = new RecordingEventStore($fixture->store);
        $uow = new EventStoreUnitOfWork($recording);
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'c1'));
        $first = self::event('created', ['course' => 'c1'], ['name' => 'first']);
        $second = self::event('renamed', ['course' => 'c1'], ['name' => 'second']);
        $firstCondition = $uow->load($query)->appendCondition();
        self::assertFalse($uow->hasPendingChanges());
        $uow->flush();
        $uow->append([], $firstCondition);
        self::assertFalse($uow->hasPendingChanges());
        self::assertSame([], $recording->appendCalls);
        $uow->append([$first], $firstCondition);
        $secondCondition = $uow->load($query)->appendCondition();
        $uow->append([$second], $secondCondition);
        self::assertTrue($uow->hasPendingChanges());
        self::assertEquals($firstCondition->checkpoint, $secondCondition->checkpoint);

        $fixture->connection?->beginTransaction();
        $uow->flush();
        $fixture->connection?->commit();

        self::assertSame([['events' => [$first, $second], 'appendConditions' => [$firstCondition, $secondCondition]]], $recording->appendCalls);
        self::assertSame(['first', 'second'], array_column(array_column($fixture->store->load($query)->events, 'payload'), 'name'));
        self::assertTrue($uow->hasPendingChanges());
        $uow->clear();
        self::assertFalse($uow->hasPendingChanges());
        $uow->flush();
        self::assertCount(1, $recording->appendCalls);
        // Clear must discard conditions as well as events, including stale snapshots.
        $uow->append([self::event('archived', ['course' => 'c1'])]);
        $uow->flush();
        self::assertSame([], $recording->appendCalls[1]['appendConditions']);
        self::assertCount(3, $fixture->store->load($query)->events);
    }

    public function test_clear_discards_buffered_work_after_a_failed_flush(): void
    {
        $store = $this->store();
        $uow = new EventStoreUnitOfWork($store);
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'c1'));
        $uow->append([self::event('created', ['course' => 'c1'], ['name' => 'pending'])], $uow->load($query)->appendCondition());
        $store->append([self::event('created', ['course' => 'c1'], ['name' => 'concurrent'])]);

        try {
            $uow->flush();
            self::fail('A stale condition must reach the wrapped store.');
        } catch (ConcurrencyException) {
        }
        self::assertTrue($uow->hasPendingChanges());
        $uow->clear();
        $uow->flush();
        self::assertFalse($uow->hasPendingChanges());
        self::assertSame(['concurrent'], array_column(array_column($uow->load($query)->events, 'payload'), 'name'));
    }
}
