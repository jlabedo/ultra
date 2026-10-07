<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\StorageTestCase;

/** @internal */
#[CoversNamespace('Ultra\EventStore')]
#[CoversNamespace('Ultra\EventStore_Postgres')]
final class OptimisticAppendTest extends StorageTestCase
{
    #[DataProvider('backends')]
    public function test_append_conditions_guard_the_exact_loaded_query_and_ignore_unrelated_events(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $pair = StreamQuery::forStream(
            new DomainIdentifier('course', 'c1'),
            new DomainIdentifier('student', 's1'),
        )->ofTypes('subscribed');
        $course = StreamQuery::forStream(new DomainIdentifier('course', 'c1'))->ofTypes('subscribed');
        $loadedPair = $store->load($pair);
        $pairCondition = $loadedPair->appendCondition();
        $courseCondition = $store->load($course)->appendCondition();
        static::assertSame($pair, $pairCondition->query);
        static::assertSame($loadedPair->checkpoint, $pairCondition->checkpoint);

        $store->append([
            self::event('subscribed', ['course' => 'other', 'student' => 's1']),
            self::event('unsubscribed', ['course' => 'c1', 'student' => 's1']),
        ]);
        $store->append(
            [self::event('subscribed', ['course' => 'c1', 'student' => 's1'])],
            $courseCondition,
            $pairCondition,
        );
        $currentPair = $store->load($pair)->appendCondition();
        $store->append([self::event('subscribed', ['course' => 'c1', 'student' => 's2'])]);
        $store->append([self::event('subscribed', ['course' => 'c1', 'student' => 's1'])], $currentPair);

        static::assertCount(2, $store->load($pair)->events);
        static::assertCount(3, $store->load($course)->events);
    }

    #[DataProvider('backends')]
    public function test_every_condition_is_validated_before_any_event_is_inserted(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $course = StreamQuery::forStream(new DomainIdentifier('course', 'c1'))->ofTypes('subscribed');
        $other = StreamQuery::forStream(new DomainIdentifier('course', 'c2'))->ofTypes('subscribed');
        $query = StreamQuery::anyOf($course, $other);
        $old = $store->load($query)->appendCondition();
        $store->append([self::event('subscribed', ['course' => 'c2'])]);
        $fresh = $store->load($course)->appendCondition();

        try {
            $store->append([self::event('subscribed', ['course' => 'c1'])], $fresh, $old);
            static::fail('The stale second condition must reject the complete append.');
        } catch (ConcurrencyException) {
        }
        static::assertSame([], $store->load($course)->events);
        static::assertCount(1, $store->load($query)->events);
    }

    #[DataProvider('backends')]
    public function test_untyped_conditions_reject_new_types_for_the_exact_dimensions(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $query = StreamQuery::forStream(
            new DomainIdentifier('faculty', 'f1'),
            new DomainIdentifier('course', 'c1'),
            new DomainIdentifier('student', 's1'),
        );
        $stale = $store->load($query)->appendCondition();
        $store->append([self::event('new.type', ['faculty' => 'f1', 'course' => 'c1', 'student' => 's1'])]);

        $this->expectException(ConcurrencyException::class);
        $store->append([self::event('other.type', ['faculty' => 'f1', 'course' => 'c1', 'student' => 's1'])], $stale);
    }

    #[DataProvider('globalQueries')]
    public function test_global_reads_cannot_become_append_conditions(StreamQuery $query): void
    {
        $store = $this->store();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Append conditions cannot use global stream queries.');
        $store->load($query)->appendCondition();
    }

    /** @return iterable<string, array{StreamQuery}> */
    public static function globalQueries(): iterable
    {
        yield 'all' => [StreamQuery::all()];
        yield 'typed global' => [StreamQuery::all()->ofTypes('created')];
        yield 'global within branches' => [StreamQuery::anyOf(
            StreamQuery::forStream(new DomainIdentifier('course', 'c1')),
            StreamQuery::all(),
        )];
    }
}
