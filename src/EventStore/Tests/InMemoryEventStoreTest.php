<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\Exception\UnsupportedQueryException;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\EventStore\StreamQuery;

function topologyProvider(): StaticEventTopologyProvider
{
    return new StaticEventTopologyProvider([
        'course.created' => new EventTopology(['courseId']),
        'course.capacity_changed' => new EventTopology(['courseId']),
        'student.subscribed' => new EventTopology(['facultyId', 'courseId', 'studentId'], [
            'courseId' => ['facultyId'],
        ]),
        'student.unsubscribed' => new EventTopology(['courseId', 'studentId']),
    ]);
}

function store(): InMemoryEventStore
{
    return new InMemoryEventStore(topologyProvider());
}

function queryVersionLastEventId(QueryVersion $queryVersion): int
{
    return $queryVersion->lastEventId;
}

test('load with empty query returns empty events and empty query version', function () {
    $store = store();
    $result = $store->load(new StreamQuery([]));

    expect($result->events)->toBe([]);
    expect(queryVersionLastEventId($result->queryVersion))->toBe(0);
});

test('typed singleton query loads matching events and returns last event id versions', function () {
    $store = store();

    $empty = $store->load(StreamQuery::forStream(
        new DomainIdentifier('courseId', 'course-123'),
    )->ofTypes('course.created'));

    expect($empty->events)->toBe([]);
    expect(queryVersionLastEventId($empty->queryVersion))->toBe(0);

    $store->append([
        new AppendableEvent(
            eventType: 'course.created',
            payload: ['name' => 'Math 101'],
            domainIds: ['courseId' => 'course-123'],
        ),
    ]);

    $result = $store->load(StreamQuery::forStream(
        new DomainIdentifier('courseId', 'course-123'),
    )->ofTypes('course.created'));

    expect($result->events)->toHaveCount(1);
    expect($result->events[0]->eventId)->toBe(1);
    expect($result->events[0]->eventType)->toBe('course.created');
    expect(queryVersionLastEventId($result->queryVersion))->toBe(1);
});

test('append with stale singleton query version throws concurrency exception', function () {
    $store = store();
    $query = StreamQuery::forStream(
        new DomainIdentifier('courseId', 'course-123'),
    )->ofTypes('course.capacity_changed');

    $staleVersion = $store->load($query)->queryVersion;

    $store->append([
        new AppendableEvent(
            eventType: 'course.capacity_changed',
            payload: ['capacity' => 30],
            domainIds: ['courseId' => 'course-123'],
        ),
    ]);

    $store->append([
        new AppendableEvent(
            eventType: 'course.capacity_changed',
            payload: ['capacity' => 31],
            domainIds: ['courseId' => 'course-123'],
        ),
    ], $staleVersion);
})->throws(ConcurrencyException::class);

test('canonical pair query does not conflict for same course and different student', function () {
    $store = store();
    $query = StreamQuery::forStream(
        new DomainIdentifier('courseId', 'course-123'),
        new DomainIdentifier('studentId', 'student-1'),
    )->ofTypes('student.subscribed');

    $store->append([
        new AppendableEvent(
            eventType: 'student.subscribed',
            payload: [],
            domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
        ),
    ]);

    $staleVersion = $store->load($query)->queryVersion;

    $store->append([
        new AppendableEvent(
            eventType: 'student.subscribed',
            payload: [],
            domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-2'],
        ),
    ]);

    $store->append([
        new AppendableEvent(
            eventType: 'student.subscribed',
            payload: [],
            domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
        ),
    ], $staleVersion);

    $result = $store->load($query);
    expect($result->events)->toHaveCount(2);
});

test('canonical pair query conflicts for the same pair', function () {
    $store = store();
    $query = StreamQuery::forStream(
        new DomainIdentifier('courseId', 'course-123'),
        new DomainIdentifier('studentId', 'student-1'),
    )->ofTypes('student.subscribed');

    $store->append([
        new AppendableEvent(
            eventType: 'student.subscribed',
            payload: [],
            domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
        ),
    ]);

    $staleVersion = $store->load($query)->queryVersion;

    $store->append([
        new AppendableEvent(
            eventType: 'student.subscribed',
            payload: [],
            domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
        ),
    ]);

    $store->append([
        new AppendableEvent(
            eventType: 'student.subscribed',
            payload: [],
            domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
        ),
    ], $staleVersion);
})->throws(ConcurrencyException::class);

test('untyped branch throws unsupported query exception', function () {
    store()->load(StreamQuery::forStream(
        new DomainIdentifier('courseId', 'course-123'),
    ));
})->throws(UnsupportedQueryException::class);

test('three identifier branch throws unsupported query exception', function () {
    store()->load(StreamQuery::forStream(
        new DomainIdentifier('facultyId', 'faculty-1'),
        new DomainIdentifier('courseId', 'course-123'),
        new DomainIdentifier('studentId', 'student-1'),
    )->ofTypes('student.subscribed'));
})->throws(UnsupportedQueryException::class);

test('non canonical pair throws unsupported query exception', function () {
    store()->load(StreamQuery::forStream(
        new DomainIdentifier('facultyId', 'faculty-1'),
        new DomainIdentifier('courseId', 'course-123'),
    )->ofTypes('student.subscribed'));
})->throws(UnsupportedQueryException::class);

test('mixed type branch with unsupported identifiers throws unsupported query exception', function () {
    store()->load(StreamQuery::forStream(
        new DomainIdentifier('courseId', 'course-123'),
        new DomainIdentifier('studentId', 'student-1'),
    )->ofTypes('course.created', 'student.subscribed'));
})->throws(UnsupportedQueryException::class);
