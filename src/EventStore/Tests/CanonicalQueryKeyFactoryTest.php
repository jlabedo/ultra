<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\CanonicalQueryKeyFactory;
use Ultra\EventStore\Exception\UnsupportedQueryException;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;

/**
 * @internal
 */
#[CoversClass(CanonicalQueryKeyFactory::class)]
final class CanonicalQueryKeyFactoryTest extends EventStoreTestCase
{
    public function test_keys_for_event_generate_canonical_singletons_and_pairs_only(): void
    {
        $factory = new CanonicalQueryKeyFactory($this->topologyProvider());

        $keys = $factory->keysForEvent('student.subscribed', [
            'facultyId' => 'faculty-1',
            'courseId' => 'course-123',
            'studentId' => 'student-1',
        ]);

        self::assertCount(5, $keys);
        self::assertContains($factory->buildKey('student.subscribed', ['courseId' => 'course-123']), $keys);
        self::assertContains($factory->buildKey('student.subscribed', ['facultyId' => 'faculty-1']), $keys);
        self::assertContains($factory->buildKey('student.subscribed', ['studentId' => 'student-1']), $keys);
        self::assertContains($factory->buildKey('student.subscribed', [
            'courseId' => 'course-123',
            'studentId' => 'student-1',
        ]), $keys);
        self::assertContains($factory->buildKey('student.subscribed', [
            'facultyId' => 'faculty-1',
            'studentId' => 'student-1',
        ]), $keys);
    }

    public function test_resolving_non_canonical_pair_throws(): void
    {
        $factory = new CanonicalQueryKeyFactory($this->topologyProvider());

        $this->expectException(UnsupportedQueryException::class);

        $factory->resolveQuery(new StreamQuery(
            ['facultyId' => 'faculty-1', 'courseId' => 'course-123'],
            ['student.subscribed'],
        ));
    }
}
