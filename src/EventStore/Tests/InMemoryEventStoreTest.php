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

test('append second event on same domain id and load returns both in order', function () {
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
        expectedVersions: ['courseId' => 0],
    ));

    $loaded = $store->load([new DomainIdentifier('courseId', 'course-123')]);

    expect($loaded)->toHaveCount(2);
    expect($loaded[0]->id)->toBe('e1');
    expect($loaded[0]->versions['courseId'])->toBe(1);
    expect($loaded[1]->id)->toBe('e2');
    expect($loaded[1]->versions['courseId'])->toBe(1);
});

test('append with wrong expected version throws concurrency exception', function () {
    $store = new InMemoryEventStore();
    $store->append(new AppendableEvent(
        id: 'e1',
        eventType: 'course-capacity-changed',
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

test('load by domain identifiers and event types', function () {
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
        expectedVersions: ['courseId' => 0],
    ));
    $store->append(new AppendableEvent(
        id: 'e3',
        eventType: 'course-capacity-changed',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 1],
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
            expectedVersions: ['courseId' => 0],
        ),
    );

    $byCourse = $store->load([new DomainIdentifier('courseId', 'course-123')]);
    expect($byCourse)->toHaveCount(3);
    expect($byCourse[0]->versions['courseId'])->toBe(1);
    expect($byCourse[1]->versions['courseId'])->toBe(2);
    expect($byCourse[2]->versions['courseId'])->toBe(1);

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

test('student-created and course-name-changed: load by domain identifiers and event names', function () {
    $store = new InMemoryEventStore();
    $store->append(new AppendableEvent(
        id: 'e1',
        eventType: 'student-created',
        payload: ['name' => 'Alice'],
        domainIds: ['studentId' => 'student-456'],
        expectedVersions: ['studentId' => 0],
    ));
    $store->append(new AppendableEvent(
        id: 'e2',
        eventType: 'course-created',
        payload: ['name' => 'Math 101'],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 0],
    ));
    $store->append(new AppendableEvent(
        id: 'e3',
        eventType: 'course-capacity-changed',
        payload: ['capacity' => 30],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 0],
    ));
    $store->append(new AppendableEvent(
        id: 'e4',
        eventType: 'course-name-changed',
        payload: ['name' => 'Mathematics 101'],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 0],
    ));

    $capacityAndSubscriptions = $store->load(
        [new DomainIdentifier('courseId', 'course-123')],
        ['course-created', 'course-capacity-changed', 'student-subscribed-to-course'],
    );
    $nameChangesOnly = $store->load(
        [new DomainIdentifier('courseId', 'course-123')],
        ['course-name-changed'],
    );
    $students = $store->load(
        [new DomainIdentifier('studentId', 'student-456')],
        ['student-created'],
    );

    expect($capacityAndSubscriptions)->toHaveCount(2);
    expect($capacityAndSubscriptions[0]->eventType)->toBe('course-created');
    expect($capacityAndSubscriptions[1]->eventType)->toBe('course-capacity-changed');
    expect($nameChangesOnly)->toHaveCount(1);
    expect($nameChangesOnly[0]->eventType)->toBe('course-name-changed');
    expect($nameChangesOnly[0]->payload['name'])->toBe('Mathematics 101');
    expect($students)->toHaveCount(1);
    expect($students[0]->eventType)->toBe('student-created');
    expect($students[0]->payload['name'])->toBe('Alice');
});

test('course-name-changed does not throw when not interested in it', function () {
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
        expectedVersions: ['courseId' => 0],
    ));

    $store->append(new AppendableEvent(
        id: 'e3',
        eventType: 'course-name-changed',
        payload: ['name' => 'New Name'],
        domainIds: ['courseId' => 'course-123'],
        expectedVersions: ['courseId' => 0],
    ));

    $forCapacityInvariant = $store->load(
        [new DomainIdentifier('courseId', 'course-123')],
        ['course-created', 'course-capacity-changed', 'student-subscribed-to-course'],
    );
    expect($forCapacityInvariant)->toHaveCount(2);
});
