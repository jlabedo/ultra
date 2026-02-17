<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\InMemoryEventStore;

test('append one event with one domain id and load by that domain id', function () {
    $store = new InMemoryEventStore();
    $store->append(new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: ['name' => 'Math 101'],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 0],
    ));

    $loaded = $store->load([new DomainIdentifier('courseId', 'course-123')]);

    expect($loaded)->toHaveCount(1);
    expect($loaded[0]->id)->toBe('e1');
    expect($loaded[0]->eventType)->toBe('course-created');
    expect($loaded[0]->domainIds)->toBe(['courseId' => 'course-123']);
    expect($loaded[0]->versions)->toBe(['courseId' => 1]);
    expect($loaded[0]->sequenceNumber)->toBe(1);
});

test('append second event with expected version 1 on same domain id and load returns both in order', function () {
    $store = new InMemoryEventStore();
    $store->append(new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 0],
    ));
    $store->append(new AppendableEvent(
        id: 'e2',
        eventType: 'course-capacity-changed',
        payload: ['capacity' => 30],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 1],
    ));

    $loaded = $store->load([new DomainIdentifier('courseId', 'course-123')]);

    expect($loaded)->toHaveCount(2);
    expect($loaded[0]->id)->toBe('e1');
    expect($loaded[0]->versions['courseId'])->toBe(1);
    expect($loaded[1]->id)->toBe('e2');
    expect($loaded[1]->versions['courseId'])->toBe(2);
});

test('append with wrong expected version throws concurrency exception', function () {
    $store = new InMemoryEventStore();
    $store->append(new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 0],
    ));

    $store->append(new AppendableEvent(
        id: 'e2',
        eventType: 'course-capacity-changed',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 0], // wrong: current is 1
    ));
})->throws(ConcurrencyException::class);

test('event with two domain ids: append with expected 0/0, load by courseId, by studentId, and by both', function () {
    $store = new InMemoryEventStore();
    $store->append(new AppendableEvent(
        id: 'e1',
        eventType: 'student-subscribed-to-course',
        payload: [],
        domainIds: ['courseId' => 'course-123', 'studentId' => 'student-456'],
        expectedVersions: ['courseId' => 0, 'studentId' => 0],
    ));

    $byCourse = $store->load([new DomainIdentifier('courseId', 'course-123')]);
    $byStudent = $store->load([new DomainIdentifier('studentId', 'student-456')]);
    $byBoth = $store->load([
        new DomainIdentifier('courseId', 'course-123'),
        new DomainIdentifier('studentId', 'student-456'),
    ]);

    expect($byCourse)->toHaveCount(1);
    expect($byStudent)->toHaveCount(1);
    expect($byBoth)->toHaveCount(1);
    expect($byBoth[0]->versions)->toBe(['courseId' => 1, 'studentId' => 1]);
});

test('load filtered by event types', function () {
    $store = new InMemoryEventStore();
    $store->append(new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 0],
    ));
    $store->append(new AppendableEvent(
        id: 'e2',
        eventType: 'course-capacity-changed',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 1],
    ));
    $store->append(new AppendableEvent(
        id: 'e3',
        eventType: 'course-capacity-changed',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 2],
    ));

    $onlyCapacity = $store->load(
        [new DomainIdentifier('courseId', 'course-123')],
        ['course-capacity-changed'],
    );
    $onlyCreated = $store->load(
        [new DomainIdentifier('courseId', 'course-123')],
        ['course-created'],
    );

    expect($onlyCapacity)->toHaveCount(2);
    expect($onlyCapacity[0]->eventType)->toBe('course-capacity-changed');
    expect($onlyCapacity[1]->eventType)->toBe('course-capacity-changed');
    expect($onlyCreated)->toHaveCount(1);
    expect($onlyCreated[0]->eventType)->toBe('course-created');
});

test('multiple events in one append: versions advance per dimension as expected', function () {
    $store = new InMemoryEventStore();
    $store->append(
        new AppendableEvent(
            id: 'e1',
            eventType: 'student-subscribed-to-course',
            payload: [],
            domainIds: ['courseId' => 'course-123', 'studentId' => 'student-1'],
            expectedVersions: ['courseId' => 0, 'studentId' => 0],
        ),
        new AppendableEvent(
            id: 'e2',
            eventType: 'student-subscribed-to-course',
            payload: [],
            domainIds: ['courseId' => 'course-123', 'studentId' => 'student-2'],
            expectedVersions: ['courseId' => 1, 'studentId' => 0],
        ),
        new AppendableEvent(
            id: 'e3',
            eventType: 'course-capacity-changed',
            payload: ['capacity' => 2],
            domainIds: ['courseId' => 'course-123'],
            expectedVersions: ['courseId' => 2],
        ),
    );

    $byCourse = $store->load([new DomainIdentifier('courseId', 'course-123')]);
    expect($byCourse)->toHaveCount(3);
    expect($byCourse[0]->versions['courseId'])->toBe(1);
    expect($byCourse[1]->versions['courseId'])->toBe(2);
    expect($byCourse[2]->versions['courseId'])->toBe(3);

    $byStudent1 = $store->load([new DomainIdentifier('studentId', 'student-1')]);
    $byStudent2 = $store->load([new DomainIdentifier('studentId', 'student-2')]);
    expect($byStudent1)->toHaveCount(1);
    expect($byStudent2)->toHaveCount(1);
    expect($byStudent1[0]->versions['studentId'])->toBe(1);
    expect($byStudent2[0]->versions['studentId'])->toBe(1);
});

test('load with empty domain ids returns empty', function () {
    $store = new InMemoryEventStore();
    $store->append(new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 0],
    ));

    $loaded = $store->load([]);

    expect($loaded)->toBe([]);
});

test('load by both domain ids returns only events matching both', function () {
    $store = new InMemoryEventStore();
    $store->append(new AppendableEvent(
        id: 'e1',
        eventType: 'student-subscribed-to-course',
        payload: [],
        domainIds: ['courseId' => 'course-123', 'studentId' => 'student-456'],
        expectedVersions: ['courseId' => 0, 'studentId' => 0],
    ));
    $store->append(new AppendableEvent(
        id: 'e2',
        eventType: 'student-subscribed-to-course',
        payload: [],
        domainIds: ['courseId' => 'course-123', 'studentId' => 'student-789'],
        expectedVersions: ['courseId' => 1, 'studentId' => 0],
    ));

    $forStudent456 = $store->load([
        new DomainIdentifier('courseId', 'course-123'),
        new DomainIdentifier('studentId', 'student-456'),
    ]);

    expect($forStudent456)->toHaveCount(1);
    expect($forStudent456[0]->id)->toBe('e1');
});
