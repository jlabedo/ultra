<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;

/**
 * @internal
 */
#[CoversClass(QueryVersion::class)]
final class QueryVersionTest extends EventStoreTestCase
{
    public function test_query_version_detects_stale_snapshots_when_the_matching_event_count_changes(): void
    {
        $query = StreamQuery::anyOf(
            StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-1'),
            )->ofTypes('student.subscribed'),
            StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-2'),
            )->ofTypes('student.subscribed'),
        );

        $this->expectException(ConcurrencyException::class);

        new QueryVersion(2, $query)->assertMatches(new QueryVersion(1, $query));
    }

    public function test_query_versions_match_when_their_versions_are_equal(): void
    {
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        new QueryVersion(2, $query)->assertMatches(new QueryVersion(2, $query));

        self::assertTrue(true);
    }
}
