<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\StreamQueryLockKeys;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;

/**
 * @internal
 */
#[CoversClass(StreamQueryLockKeys::class)]
final class StreamQueryLockKeysTest extends EventStoreTestCase
{
    public function test_write_keys_for_event_generate_singletons_and_pairs(): void
    {
        $lockKeys = new StreamQueryLockKeys();

        $keys = $lockKeys->writeKeysForEvent('student.subscribed', [
            'facultyId' => 'faculty-1',
            'courseId' => 'course-123',
            'studentId' => 'student-1',
        ]);

        self::assertCount(6, $keys);
        self::assertContains($lockKeys->buildKey('student.subscribed', ['courseId' => 'course-123']), $keys);
        self::assertContains($lockKeys->buildKey('student.subscribed', ['facultyId' => 'faculty-1']), $keys);
        self::assertContains($lockKeys->buildKey('student.subscribed', ['studentId' => 'student-1']), $keys);
        self::assertContains($lockKeys->buildKey('student.subscribed', [
            'courseId' => 'course-123',
            'facultyId' => 'faculty-1',
        ]), $keys);
        self::assertContains($lockKeys->buildKey('student.subscribed', [
            'courseId' => 'course-123',
            'studentId' => 'student-1',
        ]), $keys);
        self::assertContains($lockKeys->buildKey('student.subscribed', [
            'facultyId' => 'faculty-1',
            'studentId' => 'student-1',
        ]), $keys);
    }

    public function test_read_keys_for_pair_query_build_exact_event_type_keys(): void
    {
        $lockKeys = new StreamQueryLockKeys();

        $query = StreamQuery::forStream(
            new DomainIdentifier('facultyId', 'faculty-1'),
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('student.subscribed');

        self::assertSame([
            $lockKeys->buildKey('student.subscribed', [
                'facultyId' => 'faculty-1',
                'courseId' => 'course-123',
            ]),
        ], $lockKeys->readKeys($query));
    }

    public function test_read_keys_are_unique_across_overlapping_branches(): void
    {
        $lockKeys = new StreamQueryLockKeys();
        $branch = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
            ->ofTypes('student.subscribed')
        ;

        self::assertSame(
            [$lockKeys->buildKey('student.subscribed', ['courseId' => 'course-123'])],
            $lockKeys->readKeys(StreamQuery::anyOf($branch, $branch)),
        );
    }
}
