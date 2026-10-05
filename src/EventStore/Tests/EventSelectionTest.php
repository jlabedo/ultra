<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\StreamQueryBranch;
use Ultra\EventStore\Tests\Support\StorageTestCase;

/** @internal */
#[CoversNamespace('Ultra\EventStore')]
#[CoversNamespace('Ultra\EventStore_Postgres')]
final class EventSelectionTest extends StorageTestCase
{
    #[DataProvider('backends')]
    public function test_empty_and_global_queries_keep_their_distinct_meanings(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        self::assertSame([], $store->load(StreamQuery::none())->events);
        $store->append([
            self::event('course.created', ['course' => 'c1']),
            self::event('student.subscribed', ['student' => 's1']),
        ]);

        self::assertSame([], $store->load(StreamQuery::none())->events);
        self::assertSame([], $store->load(StreamQuery::forStream())->events);
        self::assertSame(['course.created', 'student.subscribed'], array_column($store->load(StreamQuery::all())->events, 'eventType'));
        self::assertSame(['course.created'], array_column($store->load(StreamQuery::all()->ofTypes('course.created'))->events, 'eventType'));
        self::assertSame(['course.created', 'student.subscribed'], array_column($store->load(new StreamQuery([
            new StreamQueryBranch([]),
            new StreamQueryBranch(['course' => 'c1']),
        ]))->events, 'eventType'));
    }

    #[DataProvider('backends')]
    public function test_identifiers_are_conjunctive_and_types_belong_to_their_own_or_branch(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $store->append([
            self::event('subscribed', ['faculty' => 'f1', 'course' => 'c1', 'student' => 's1'], ['slot' => 1]),
            self::event('unsubscribed', ['faculty' => 'f1', 'course' => 'c1', 'student' => 's1'], ['slot' => 2]),
            self::event('subscribed', ['faculty' => 'f1', 'course' => 'c1', 'student' => 's2'], ['slot' => 3]),
            self::event('subscribed', ['faculty' => 'f2', 'course' => 'c1', 'student' => 's1'], ['slot' => 4]),
            self::event('subscribed', ['course' => 'c1'], ['slot' => 5]),
            self::event('unsubscribed', ['faculty' => 'f1', 'course' => 'c2', 'student' => 's1'], ['slot' => 6]),
        ]);
        $pair = StreamQuery::forStream(new DomainIdentifier('course', 'c1'), new DomainIdentifier('student', 's1'));
        $triple = StreamQuery::forStream(new DomainIdentifier('student', 's1'), new DomainIdentifier('faculty', 'f1'), new DomainIdentifier('course', 'c1'));
        $other = StreamQuery::forStream(new DomainIdentifier('course', 'c2'))->ofTypes('unsubscribed');

        self::assertSame([1, 2, 4], array_column(array_column($store->load($pair)->events, 'payload'), 'slot'));
        self::assertSame([1, 2], array_column(array_column($store->load($triple)->events, 'payload'), 'slot'));
        self::assertSame([1], array_column(array_column($store->load($triple->ofTypes('subscribed'))->events, 'payload'), 'slot'));
        $query = StreamQuery::anyOf($pair->ofTypes('subscribed'), StreamQuery::anyOf($other, $triple->ofTypes('subscribed')));
        self::assertSame([1, 4, 6], array_column(array_column($store->load($query)->events, 'payload'), 'slot'));
        self::assertSame([1, 2, 4, 6], array_column(array_column($store->load($query->ofTypes('subscribed', 'unsubscribed'))->events, 'payload'), 'slot'));
    }

    public function test_query_builders_preserve_the_original_and_normalize_named_type_arguments(): void
    {
        $course = StreamQuery::forStream(new DomainIdentifier('course', 'c1'));
        $student = StreamQuery::forStream(new DomainIdentifier('student', 's1'));
        $query = StreamQuery::anyOf($course, StreamQuery::anyOf($student))->ofTypes(created: 'created', updated: 'updated');

        self::assertSame([], $course->branches[0]->eventTypes);
        self::assertSame([], $student->branches[0]->eventTypes);
        self::assertCount(2, $query->branches);
        foreach ($query->branches as $branch) {
            self::assertSame(['created', 'updated'], $branch->eventTypes);
        }
        self::assertTrue($query->matches(self::event('created', ['course' => 'c1'])));
        self::assertFalse($query->matches(self::event('deleted', ['student' => 's1'])));
        self::assertFalse($query->matches(self::event('created', ['course' => 'other'])));
        self::assertFalse(StreamQuery::none()->matches(self::event(domainIds: ['course' => 'c1'])));
    }
}
