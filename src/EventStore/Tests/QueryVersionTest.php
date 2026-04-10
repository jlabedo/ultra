<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StreamQuery;

test('query version detects stale snapshots when the matching event count changes', function () {
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

    new QueryVersion(2, $query)->assertMatches(new QueryVersion(1, $query));
})->throws(ConcurrencyException::class);

test('query versions match when their versions are equal', function () {
    $query = StreamQuery::forStream(
        new DomainIdentifier('courseId', 'course-123'),
    )->ofTypes('course.created');

    new QueryVersion(2, $query)->assertMatches(new QueryVersion(2, $query));

    expect(true)->toBeTrue();
});
