<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\CanonicalQueryKeyFactory;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;

/**
 * @internal
 */
#[CoversClass(CanonicalQueryKeyFactory::class)]
final class CanonicalQueryKeyFactoryTest extends EventStoreTestCase
{
    public function test_keys_for_event_generate_singletons_and_pairs(): void
    {
        $factory = new CanonicalQueryKeyFactory();

        $keys = $factory->keysForEvent('student.subscribed', [
            'facultyId' => 'faculty-1',
            'courseId' => 'course-123',
            'studentId' => 'student-1',
        ]);

        self::assertCount(6, $keys);
        self::assertContains($factory->buildKey('student.subscribed', ['courseId' => 'course-123']), $keys);
        self::assertContains($factory->buildKey('student.subscribed', ['facultyId' => 'faculty-1']), $keys);
        self::assertContains($factory->buildKey('student.subscribed', ['studentId' => 'student-1']), $keys);
        self::assertContains($factory->buildKey('student.subscribed', [
            'courseId' => 'course-123',
            'facultyId' => 'faculty-1',
        ]), $keys);
        self::assertContains($factory->buildKey('student.subscribed', [
            'courseId' => 'course-123',
            'studentId' => 'student-1',
        ]), $keys);
        self::assertContains($factory->buildKey('student.subscribed', [
            'facultyId' => 'faculty-1',
            'studentId' => 'student-1',
        ]), $keys);
    }

    public function test_resolving_pair_query_builds_exact_event_type_keys(): void
    {
        $factory = new CanonicalQueryKeyFactory();

        $query = $factory->resolveQuery(new StreamQuery(
            ['facultyId' => 'faculty-1', 'courseId' => 'course-123'],
            ['student.subscribed'],
        ));

        self::assertSame([
            $factory->buildKey('student.subscribed', [
                'facultyId' => 'faculty-1',
                'courseId' => 'course-123',
            ]),
        ], $query->allKeys());
    }
}
