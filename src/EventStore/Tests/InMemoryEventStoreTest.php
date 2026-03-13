<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\StreamVersion;

test('append one event with one domain id and load by that domain id', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: ['name' => 'Math 101'],
        domainIds: ['courseId' => 'course-123'],
    )]);

    $result = $store->load(new StreamQuery(['courseId' => 'course-123']));

    expect($result->events)->toHaveCount(1);
    expect($result->events[0]->eventId)->toBe(1);
    expect($result->events[0]->id)->toBe('e1');
    expect($result->events[0]->eventType)->toBe('course-created');
    expect($result->events[0]->domainIds)->toBe(['courseId' => 'course-123']);
    $streamKey = "courseId\0course-123\0course-created";
    expect($result->streamVersion->version)->toHaveKey($streamKey);
    expect($result->streamVersion->version[$streamKey])->toBe(1);
});

test('append second event on same domain id and load returns both in order', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
    )]);
    $store->append([new AppendableEvent(
        id: 'e2',
        eventType: 'course-capacity-changed',
        payload: ['capacity' => 30],
        domainIds: ['courseId' => 'course-123'],
    )]);

    $result = $store->load(new StreamQuery(['courseId' => 'course-123']));

    expect($result->events)->toHaveCount(2);
    expect($result->events[0]->id)->toBe('e1');
    expect($result->events[0]->eventId)->toBe(1);
    expect($result->events[1]->id)->toBe('e2');
    expect($result->events[1]->eventId)->toBe(2);
});

test('append with wrong expected version throws concurrency exception', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-capacity-changed',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
    )]);

    $staleVersion = new StreamVersion(
        ["courseId\0course-123\0course-capacity-changed" => 0],
        new StreamQuery(['courseId' => 'course-123'], ['course-capacity-changed']),
    );

    $store->append([
        new AppendableEvent(
            id: 'e2',
            eventType: 'course-capacity-changed',
            payload: [],
            domainIds: ['courseId' => 'course-123'],
        ),
    ], $staleVersion);
})->throws(ConcurrencyException::class);

test('append with matching expected version succeeds', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-capacity-changed',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
    )]);

    $result = $store->load(new StreamQuery(['courseId' => 'course-123'], ['course-capacity-changed']));

    $store->append([
        new AppendableEvent(
            id: 'e2',
            eventType: 'course-capacity-changed',
            payload: ['capacity' => 30],
            domainIds: ['courseId' => 'course-123'],
        ),
    ], $result->streamVersion);

    $after = $store->load(new StreamQuery(['courseId' => 'course-123']));
    expect($after->events)->toHaveCount(2);
});

test('event with two domain ids: load by courseId, by studentId, and by both', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'student-subscribed-to-course',
        payload: [],
        domainIds: ['courseId' => 'course-123', 'studentId' => 'student-456'],
    )]);

    $byCourse = $store->load(new StreamQuery(['courseId' => 'course-123']));
    $byStudent = $store->load(new StreamQuery(['studentId' => 'student-456']));
    $byBoth = $store->load(new StreamQuery([
        'courseId' => 'course-123',
        'studentId' => 'student-456',
    ]));

    expect($byCourse->events)->toHaveCount(1);
    expect($byStudent->events)->toHaveCount(1);
    expect($byBoth->events)->toHaveCount(1);
    expect($byBoth->streamVersion->version)->toHaveCount(2);
});

test('load by domain identifiers and event types', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
    )]);
    $store->append([new AppendableEvent(
        id: 'e2',
        eventType: 'course-capacity-changed',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
    )]);
    $store->append([new AppendableEvent(
        id: 'e3',
        eventType: 'course-capacity-changed',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
    )]);

    $onlyCapacity = $store->load(new StreamQuery(
        ['courseId' => 'course-123'],
        ['course-capacity-changed'],
    ));
    $onlyCreated = $store->load(new StreamQuery(
        ['courseId' => 'course-123'],
        ['course-created'],
    ));

    expect($onlyCapacity->events)->toHaveCount(2);
    expect($onlyCapacity->events[0]->eventType)->toBe('course-capacity-changed');
    expect($onlyCapacity->events[1]->eventType)->toBe('course-capacity-changed');
    expect($onlyCreated->events)->toHaveCount(1);
    expect($onlyCreated->events[0]->eventType)->toBe('course-created');
});

test('multiple events in one append: versions advance per stream', function () {
    $store = new InMemoryEventStore();
    $store->append([
        new AppendableEvent(
            id: 'e1',
            eventType: 'student-subscribed-to-course',
            payload: [],
            domainIds: ['courseId' => 'course-123', 'studentId' => 'student-1'],
        ),
        new AppendableEvent(
            id: 'e2',
            eventType: 'student-subscribed-to-course',
            payload: [],
            domainIds: ['courseId' => 'course-123', 'studentId' => 'student-2'],
        ),
        new AppendableEvent(
            id: 'e3',
            eventType: 'course-capacity-changed',
            payload: ['capacity' => 2],
            domainIds: ['courseId' => 'course-123'],
        ),
    ]);

    $byCourse = $store->load(new StreamQuery(['courseId' => 'course-123']));
    expect($byCourse->events)->toHaveCount(3);
    expect($byCourse->events[0]->eventId)->toBe(1);
    expect($byCourse->events[1]->eventId)->toBe(2);
    expect($byCourse->events[2]->eventId)->toBe(3);

    $byStudent1 = $store->load(new StreamQuery(['studentId' => 'student-1']));
    $byStudent2 = $store->load(new StreamQuery(['studentId' => 'student-2']));
    expect($byStudent1->events)->toHaveCount(1);
    expect($byStudent2->events)->toHaveCount(1);
});

test('load with empty query returns empty', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
    )]);

    $result = $store->load(new StreamQuery([]));

    expect($result->events)->toBe([]);
    expect($result->streamVersion->version)->toBe([]);
});

test('load by both domain ids returns only events matching both', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'student-subscribed-to-course',
        payload: [],
        domainIds: ['courseId' => 'course-123', 'studentId' => 'student-456'],
    )]);
    $store->append([new AppendableEvent(
        id: 'e2',
        eventType: 'student-subscribed-to-course',
        payload: [],
        domainIds: ['courseId' => 'course-123', 'studentId' => 'student-789'],
    )]);

    $forStudent456 = $store->load(new StreamQuery([
        'courseId' => 'course-123',
        'studentId' => 'student-456',
    ]));

    expect($forStudent456->events)->toHaveCount(1);
    expect($forStudent456->events[0]->id)->toBe('e1');
});

test('student-created and course-name-changed: load by domain identifiers and event names', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'student-created',
        payload: ['name' => 'Alice'],
        domainIds: ['studentId' => 'student-456'],
    )]);
    $store->append([new AppendableEvent(
        id: 'e2',
        eventType: 'course-created',
        payload: ['name' => 'Math 101'],
        domainIds: ['courseId' => 'course-123'],
    )]);
    $store->append([new AppendableEvent(
        id: 'e3',
        eventType: 'course-capacity-changed',
        payload: ['capacity' => 30],
        domainIds: ['courseId' => 'course-123'],
    )]);
    $store->append([new AppendableEvent(
        id: 'e4',
        eventType: 'course-name-changed',
        payload: ['name' => 'Mathematics 101'],
        domainIds: ['courseId' => 'course-123'],
    )]);

    $capacityAndSubscriptions = $store->load(new StreamQuery(
        ['courseId' => 'course-123'],
        ['course-created', 'course-capacity-changed', 'student-subscribed-to-course'],
    ));
    $nameChangesOnly = $store->load(new StreamQuery(
        ['courseId' => 'course-123'],
        ['course-name-changed'],
    ));
    $students = $store->load(new StreamQuery(
        ['studentId' => 'student-456'],
        ['student-created'],
    ));

    expect($capacityAndSubscriptions->events)->toHaveCount(2);
    expect($capacityAndSubscriptions->events[0]->eventType)->toBe('course-created');
    expect($capacityAndSubscriptions->events[1]->eventType)->toBe('course-capacity-changed');
    expect($nameChangesOnly->events)->toHaveCount(1);
    expect($nameChangesOnly->events[0]->eventType)->toBe('course-name-changed');
    expect($nameChangesOnly->events[0]->payload['name'])->toBe('Mathematics 101');
    expect($students->events)->toHaveCount(1);
    expect($students->events[0]->eventType)->toBe('student-created');
    expect($students->events[0]->payload['name'])->toBe('Alice');
});

test('course-name-changed does not throw when not interested in it', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
    )]);
    $store->append([new AppendableEvent(
        id: 'e2',
        eventType: 'course-capacity-changed',
        payload: ['capacity' => 30],
        domainIds: ['courseId' => 'course-123'],
    )]);

    $store->append([new AppendableEvent(
        id: 'e3',
        eventType: 'course-name-changed',
        payload: ['name' => 'New Name'],
        domainIds: ['courseId' => 'course-123'],
    )]);

    $forCapacityInvariant = $store->load(new StreamQuery(
        ['courseId' => 'course-123'],
        ['course-created', 'course-capacity-changed', 'student-subscribed-to-course'],
    ));
    expect($forCapacityInvariant->events)->toHaveCount(2);
});

test('load by domain identifiers and event types: multiple events in one append', function () {
    $store = new InMemoryEventStore();
    $store->append([
        new AppendableEvent(
            id: 'e1',
            eventType: 'student-subscribed-to-course',
            payload: [],
            domainIds: ['courseId' => 'course-123', 'studentId' => 'student-456'],
        ),
        new AppendableEvent(
            id: 'e2',
            eventType: 'student-subscribed-to-course',
            payload: [],
            domainIds: ['courseId' => 'course-123', 'studentId' => 'student-789'],
        ),
        new AppendableEvent(
            id: 'e3',
            eventType: 'student-subscribed-to-course',
            payload: [],
            domainIds: ['courseId' => 'course-456', 'studentId' => 'student-1011'],
        ),
        new AppendableEvent(
            id: 'e4',
            eventType: 'course-capacity-changed',
            payload: ['capacity' => 30],
            domainIds: ['courseId' => 'course-123'],
        ),
    ]);

    $forCourse123 = $store->load(new StreamQuery(
        ['courseId' => 'course-123'],
        ['student-subscribed-to-course'],
    ));

    expect($forCourse123->events)->toHaveCount(2);
    expect($forCourse123->events[0]->id)->toBe('e1');
    expect($forCourse123->events[1]->id)->toBe('e2');
});

test('append without expected version never throws concurrency', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-capacity-changed',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
    )]);

    $store->append([new AppendableEvent(
        id: 'e2',
        eventType: 'course-capacity-changed',
        payload: [],
        domainIds: ['courseId' => 'course-123'],
    )], null);

    $result = $store->load(new StreamQuery(['courseId' => 'course-123']));
    expect($result->events)->toHaveCount(2);
});

test('stream version from load reflects last eventId per stream in result', function () {
    $store = new InMemoryEventStore();
    $store->append([
        new AppendableEvent(
            id: 'e1',
            eventType: 'student-subscribed-to-course',
            payload: [],
            domainIds: ['courseId' => 'course-123', 'studentId' => 'student-456'],
        ),
        new AppendableEvent(
            id: 'e2',
            eventType: 'course-capacity-changed',
            payload: [],
            domainIds: ['courseId' => 'course-123'],
        ),
    ]);

    $result = $store->load(new StreamQuery(
        ['courseId' => 'course-123'],
        ['student-subscribed-to-course', 'course-capacity-changed'],
    ));

    expect($result->events)->toHaveCount(2);
    $courseSubscriptionKey = "courseId\0course-123\0student-subscribed-to-course";
    $capacityKey = "courseId\0course-123\0course-capacity-changed";
    expect($result->streamVersion->version[$courseSubscriptionKey] ?? null)->toBe(1);
    expect($result->streamVersion->version[$capacityKey] ?? null)->toBe(2);
});

test('append with expected version from empty result set succeeds when stream does not exist yet', function () {
    $store = new InMemoryEventStore();
    $query = new StreamQuery(['courseId' => 'course-999'], ['course-capacity-changed']);

    $result = $store->load($query);
    expect($result->events)->toBe([]);
    expect($result->streamVersion->version)->toBe([]);
    expect($result->streamVersion->query)->toBe($query);

    $store->append([
        new AppendableEvent(
            id: 'e1',
            eventType: 'course-capacity-changed',
            payload: ['capacity' => 10],
            domainIds: ['courseId' => 'course-999'],
        ),
    ], $result->streamVersion);

    $after = $store->load($query);
    expect($after->events)->toHaveCount(1);
    expect($after->events[0]->eventId)->toBe(1);
});

test('fluent API: forStream with DomainIdentifier loads by stream', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['course' => 'math-101'],
    )]);

    $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'));
    $result = $store->load($query);

    expect($result->events)->toHaveCount(1);
    expect($result->events[0]->domainIds['course'])->toBe('math-101');
});

test('fluent API: anyOf merges branches with OR semantics', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['course' => 'math-101'],
    )]);
    $store->append([new AppendableEvent(
        id: 'e2',
        eventType: 'student-enrolled',
        payload: [],
        domainIds: ['student' => 'alice'],
    )]);

    $query = StreamQuery::anyOf(
        StreamQuery::forStream(new DomainIdentifier('course', 'math-101')),
        StreamQuery::forStream(new DomainIdentifier('student', 'alice')),
    );
    $result = $store->load($query);

    expect($result->events)->toHaveCount(2);
});

test('fluent API: forStream with multiple identifiers has AND semantics', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'student-subscribed',
        payload: [],
        domainIds: ['course' => 'math-101', 'student' => 'alice'],
    )]);

    $query = StreamQuery::forStream(
        new DomainIdentifier('course', 'math-101'),
        new DomainIdentifier('student', 'alice'),
    );
    $result = $store->load($query);

    expect($result->events)->toHaveCount(1);
    expect($result->events[0]->eventType)->toBe('student-subscribed');
});

test('fluent API: ofTypes filters by event type names', function () {
    $store = new InMemoryEventStore();
    $store->append([new AppendableEvent(
        id: 'e1',
        eventType: 'course-created',
        payload: [],
        domainIds: ['course' => 'math-101'],
    )]);
    $store->append([new AppendableEvent(
        id: 'e2',
        eventType: 'course-updated',
        payload: [],
        domainIds: ['course' => 'math-101'],
    )]);

    $query = StreamQuery::forStream(new DomainIdentifier('course', 'math-101'))
        ->ofTypes('course-created')
    ;
    $result = $store->load($query);

    expect($result->events)->toHaveCount(1);
    expect($result->events[0]->eventType)->toBe('course-created');
});
