<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StoredEvent;
use Ultra\EventStore\StreamQuery;

function storedEvent(array $domainIds, string $eventType = 'test.event'): StoredEvent
{
    return new StoredEvent(
        eventId: 1,
        id: 'e1',
        eventType: $eventType,
        payload: [],
        domainIds: $domainIds,
    );
}

test('matches: empty query matches no event', function () {
    $query = new StreamQuery([], [], []);
    $event = storedEvent(['course' => 'math-101']);

    expect($query->matches($event))->toBeFalse();
});

test('matches: forStream with one identifier matches event with that dimension and value', function () {
    $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));
    $event = storedEvent(['course' => 'math-101']);

    expect($query->matches($event))->toBeTrue();
});

test('matches: forStream does not match when dimension value differs', function () {
    $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));
    $event = storedEvent(['course' => 'other-101']);

    expect($query->matches($event))->toBeFalse();
});

test('matches: forStream does not match when dimension is missing on event', function () {
    $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));
    $event = storedEvent(['student' => 'alice']);

    expect($query->matches($event))->toBeFalse();
});

test('matches: forStream with multiple identifiers (AND) matches event with all', function () {
    $query = StreamQuery::forStream(
        new DomainIdentifier('course', 'math-101'),
        new DomainIdentifier('student', 'alice'),
    );
    $event = storedEvent(['course' => 'math-101', 'student' => 'alice']);

    expect($query->matches($event))->toBeTrue();
});

test('matches: forStream with multiple identifiers does not match when one is missing', function () {
    $query = StreamQuery::forStream(
        new DomainIdentifier('course', 'math-101'),
        new DomainIdentifier('student', 'alice'),
    );
    $event = storedEvent(['course' => 'math-101']);

    expect($query->matches($event))->toBeFalse();
});

test('matches: anyOf matches event that matches any branch', function () {
    $query = StreamQuery::anyOf(
        StreamQuery::forStream(new DomainIdentifier('course', 'math-101')),
        StreamQuery::forStream(new DomainIdentifier('student', 'alice')),
    );
    $eventCourse = storedEvent(['course' => 'math-101']);
    $eventStudent = storedEvent(['student' => 'alice']);
    $eventNeither = storedEvent(['other' => 'x']);

    expect($query->matches($eventCourse))->toBeTrue();
    expect($query->matches($eventStudent))->toBeTrue();
    expect($query->matches($eventNeither))->toBeFalse();
});

test('matches: ofTypes filters by event type', function () {
    $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'))
        ->ofTypes('course.created', 'course.updated');
    $eventMatching = storedEvent(['course' => 'math-101'], 'course.created');
    $eventNotMatching = storedEvent(['course' => 'math-101'], 'course.deleted');

    expect($query->matches($eventMatching))->toBeTrue();
    expect($query->matches($eventNotMatching))->toBeFalse();
});

test('matches: ofTypes filters by event type within OR criteria', function () {
    $query = StreamQuery::anyOf(
        StreamQuery::forStream(new DomainIdentifier('course', 'math-101'))
            ->ofTypes('course.created'),
        StreamQuery::forStream(new DomainIdentifier('course', 'math-102'))
            ->ofTypes('course.updated'),
    );
    $eventMatching = storedEvent(['course' => 'math-101'], 'course.created');
    $eventNotMatching = storedEvent(['course' => 'math-102'], 'course.created');

    expect($query->matches($eventMatching))->toBeTrue();
    expect($query->matches($eventNotMatching))->toBeFalse();
});

test('matches: deeply nested OR criteria', function () {
    $query = StreamQuery::anyOf(
        StreamQuery::forStream(new DomainIdentifier('course', 'math-101'))
            ->ofTypes('course.created'),
        StreamQuery::anyOf(
            StreamQuery::forStream(new DomainIdentifier('course', 'math-102'))
                ->ofTypes('course.updated'),
            StreamQuery::forStream(new DomainIdentifier('course', 'math-103'))
                ->ofTypes('course.deleted'),
        ),
    );
    $eventMatching = storedEvent(['course' => 'math-101'], 'course.created');
    $eventMatching2 = storedEvent(['course' => 'math-102'], 'course.updated');
    $eventMatching3 = storedEvent(['course' => 'math-103'], 'course.deleted');
    $eventNotMatching = storedEvent(['course' => 'math-101'], 'course.deleted');
    $eventNotMatching2 = storedEvent(['course' => 'math-102'], 'course.created');
    $eventNotMatching3 = storedEvent(['course' => 'math-103'], 'course.created');

    expect($query->matches($eventMatching))->toBeTrue();
    expect($query->matches($eventMatching2))->toBeTrue();
    expect($query->matches($eventMatching3))->toBeTrue();
    expect($query->matches($eventNotMatching))->toBeFalse();
    expect($query->matches($eventNotMatching2))->toBeFalse();
    expect($query->matches($eventNotMatching3))->toBeFalse();
});

test('matches: empty ofTypes means all event types match (domain match only)', function () {
    $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));
    $event = storedEvent(['course' => 'math-101'], 'any.event.type');

    expect($query->matches($event))->toBeTrue();
});

test('matches: legacy constructor with domainIdentifiers', function () {
    $query = new StreamQuery(['course' => 'math-101'], []);
    $event = storedEvent(['course' => 'math-101']);

    expect($query->matches($event))->toBeTrue();
});

test('matches: legacy constructor with domainIdentifiers and eventNames', function () {
    $query = new StreamQuery(['course' => 'math-101'], ['course.created']);
    $eventMatch = storedEvent(['course' => 'math-101'], 'course.created');
    $eventNoType = storedEvent(['course' => 'math-101'], 'course.updated');

    expect($query->matches($eventMatch))->toBeTrue();
    expect($query->matches($eventNoType))->toBeFalse();
});
