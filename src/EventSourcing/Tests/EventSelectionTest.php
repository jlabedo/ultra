<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\StreamQueryBranch;
use Ultra\EventStore\Tests\Support\MutableDomainId;
use Ultra\EventStore\Tests\Support\StorageTestCase;

/** @internal */
#[CoversNamespace('Ultra\EventStore')]
#[CoversNamespace('Ultra\EventStore_Postgres')]
final class EventSelectionTest extends StorageTestCase
{
    /** @param list<int> $expectedSlots */
    #[DataProvider('segmentRanges')]
    public function test_segments_include_their_boundaries_and_exclude_missing_dimensions(
        string $backend,
        int $from,
        int $to,
        array $expectedSlots,
    ): void {
        $store = $this->fixture($backend)->store;
        $store->append([
            self::event('created', ['course' => 'course-484'], ['slot' => 0]),
            self::event('created', ['course' => 'course-262'], ['slot' => 127]),
            self::event('created', ['course' => 'course-161'], ['slot' => 128]),
            self::event('created', ['course' => 'course-1198'], ['slot' => 255]),
            self::event('created', ['student' => 'course-484'], ['slot' => 'missing']),
            self::event('renamed', ['course' => 'course-484'], ['slot' => 'other type']),
        ]);

        $result = $store->load(StreamQuery::forSegment('course', $from, $to, 'created'));

        static::assertSame($expectedSlots, array_column(array_column($result->events, 'payload'), 'slot'));
        static::assertFalse($result->hasMore);
    }

    /** @return iterable<string, array{string, int, int, list<int>}> */
    public static function segmentRanges(): iterable
    {
        foreach (self::backends() as $backend => [$implementation]) {
            yield $backend . ' full range' => [$implementation, 0, 255, [0, 127, 128, 255]];
            yield $backend . ' left segment' => [$implementation, 0, 127, [0, 127]];
            yield $backend . ' right segment' => [$implementation, 128, 255, [128, 255]];
            yield $backend . ' first bucket' => [$implementation, 0, 0, [0]];
            yield $backend . ' last bucket' => [$implementation, 255, 255, [255]];
        }
    }

    #[DataProvider('backends')]
    public function test_segment_filters_compose_with_identifiers_and_branch_local_types(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $store->append([
            self::event('created', ['course' => 'course-484', 'tenant' => 'one'], ['slot' => 1]),
            self::event('created', ['course' => 'course-484', 'tenant' => 'two'], ['slot' => 2]),
            self::event('created', ['course' => 'course-1198', 'tenant' => 'one'], ['slot' => 3]),
            self::event('renamed', ['course' => 'course-484', 'tenant' => 'one'], ['slot' => 4]),
            self::event('renamed', ['course' => 'course-1198', 'tenant' => 'two'], ['slot' => 5]),
        ]);
        $query = StreamQuery::anyOf(
            new StreamQuery([
                new StreamQueryBranch(['tenant' => 'one'], ['created'])->withBucketRange('course', 0, 127),
            ]),
            StreamQuery::forSegment('course', 255, 255, 'renamed'),
        );

        static::assertSame([1, 5], array_column(array_column($store->load($query)->events, 'payload'), 'slot'));
        static::assertSame(
            [1, 2, 4],
            array_column(array_column($store->load(StreamQuery::forSegment('course', 0, 127))->events, 'payload'), 'slot'),
        );
    }

    #[DataProvider('backends')]
    public function test_segment_hashing_preserves_empty_and_escaped_utf8_identifiers(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $dimension = "coursé'\\";
        $values = ['', "été'\\", '000123'];
        $store->append(array_map(
            static fn(string $value) => self::event(domainIds: [$dimension => $value], payload: ['id' => $value]),
            $values,
        ));
        $store->append([self::event(domainIds: ['student' => 'one'], payload: ['id' => 'missing'])]);

        foreach ($values as $value) {
            $bucket = BucketHasher::bucketFor($value);
            $result = $store->load(StreamQuery::forSegment($dimension, $bucket, $bucket));

            static::assertSame([$value], array_column(array_column($result->events, 'payload'), 'id'));
        }
    }

    #[DataProvider('backends')]
    public function test_stringable_identifiers_capture_strings_and_select_the_same_stream(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        $uuid = Uuid::fromString('12345678-1234-4234-8234-123456789abc');
        $studentId = new MutableDomainId('student-1');
        $identifier = new DomainIdentifier('student', $studentId);
        $query = StreamQuery::forStream(new DomainIdentifier('course', $uuid), $identifier);
        $studentId->value = 'student-2';
        $store->append([
            self::event('subscribed', ['course' => (string) $uuid, 'student' => 'student-1']),
            self::event('subscribed', ['course' => (string) $uuid, 'student' => 'student-2']),
        ]);

        static::assertSame('student-1', $identifier->value);
        static::assertSame(['student' => 'student-1'], $identifier->toMap());
        static::assertSame(
            [
                'course' => (string) $uuid,
                'student' => 'student-1',
            ],
            $query->branches[0]->domainIdentifiers,
        );
        $events = $store->load($query)->events;
        static::assertCount(1, $events);
        static::assertSame(['course' => (string) $uuid, 'student' => 'student-1'], $events[0]->domainIds);
    }

    #[DataProvider('backends')]
    public function test_empty_and_global_queries_keep_their_distinct_meanings(string $backend): void
    {
        $store = $this->fixture($backend)->store;
        static::assertSame([], $store->load(StreamQuery::none())->events);
        $store->append([
            self::event('course.created', ['course' => 'c1']),
            self::event('student.subscribed', ['student' => 's1']),
        ]);

        static::assertSame([], $store->load(StreamQuery::none())->events);
        static::assertSame([], $store->load(StreamQuery::forStream())->events);
        static::assertSame(
            ['course.created', 'student.subscribed'],
            array_column($store->load(StreamQuery::all())->events, 'eventType'),
        );
        static::assertSame(
            ['course.created'],
            array_column($store->load(StreamQuery::all()->ofTypes('course.created'))->events, 'eventType'),
        );
        static::assertSame(
            ['course.created', 'student.subscribed'],
            array_column(
                $store->load(new StreamQuery([
                    new StreamQueryBranch([]),
                    new StreamQueryBranch(['course' => 'c1']),
                ]))->events,
                'eventType',
            ),
        );
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
        $triple = StreamQuery::forStream(
            new DomainIdentifier('student', 's1'),
            new DomainIdentifier('faculty', 'f1'),
            new DomainIdentifier('course', 'c1'),
        );
        $other = StreamQuery::forStream(new DomainIdentifier('course', 'c2'))->ofTypes('unsubscribed');

        static::assertSame([1, 2, 4], array_column(array_column($store->load($pair)->events, 'payload'), 'slot'));
        static::assertSame([1, 2], array_column(array_column($store->load($triple)->events, 'payload'), 'slot'));
        static::assertSame(
            [1],
            array_column(array_column($store->load($triple->ofTypes('subscribed'))->events, 'payload'), 'slot'),
        );
        $query = StreamQuery::anyOf(
            $pair->ofTypes('subscribed'),
            StreamQuery::anyOf($other, $triple->ofTypes('subscribed')),
        );
        static::assertSame([1, 4, 6], array_column(array_column($store->load($query)->events, 'payload'), 'slot'));
        static::assertSame(
            [1, 2, 4, 6],
            array_column(
                array_column($store->load($query->ofTypes('subscribed', 'unsubscribed'))->events, 'payload'),
                'slot',
            ),
        );
    }

    public function test_query_builders_preserve_the_original_and_normalize_named_type_arguments(): void
    {
        $course = StreamQuery::forStream(new DomainIdentifier('course', 'c1'));
        $student = StreamQuery::forStream(new DomainIdentifier('student', 's1'));
        $query = StreamQuery::anyOf($course, StreamQuery::anyOf($student))->ofTypes(
            created: 'created',
            updated: 'updated',
        );

        static::assertSame([], $course->branches[0]->eventTypes);
        static::assertSame([], $student->branches[0]->eventTypes);
        static::assertCount(2, $query->branches);
        foreach ($query->branches as $branch) {
            static::assertSame(['created', 'updated'], $branch->eventTypes);
        }
        static::assertTrue($query->matches(self::event('created', ['course' => 'c1'])));
        static::assertFalse($query->matches(self::event('deleted', ['student' => 's1'])));
        static::assertFalse($query->matches(self::event('created', ['course' => 'other'])));
        static::assertFalse(StreamQuery::none()->matches(self::event(domainIds: ['course' => 'c1'])));
    }
}
