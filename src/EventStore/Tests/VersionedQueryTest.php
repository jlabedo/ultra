<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\VersionedQuery;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;
use Ultra\EventStore\VersionToken;

final readonly class VersionedQueryTestToken implements VersionToken
{
    public function matches(VersionToken $expected): bool
    {
        return $this === $expected;
    }
}

/**
 * @internal
 */
#[CoversClass(VersionedQuery::class)]
final class VersionedQueryTest extends EventStoreTestCase
{
    public function test_versioned_query_carries_an_opaque_token_and_the_loaded_query(): void
    {
        $token = new VersionedQueryTestToken();
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

        $versionedQuery = new VersionedQuery($token, $query);

        self::assertSame($token, $versionedQuery->token);
        self::assertSame($query, $versionedQuery->query);
    }
}
