<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;
use Ultra\EventStore\VersionToken;

final readonly class QueryVersionTestToken implements VersionToken
{
    public function matches(VersionToken $expected): bool
    {
        return $this === $expected;
    }
}

/**
 * @internal
 */
#[CoversClass(QueryVersion::class)]
final class QueryVersionTest extends EventStoreTestCase
{
    public function test_query_version_carries_an_opaque_token_and_the_loaded_query(): void
    {
        $token = new QueryVersionTestToken();
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

        $queryVersion = new QueryVersion($token, $query);

        self::assertSame($token, $queryVersion->token);
        self::assertSame($query, $queryVersion->query);
    }
}
