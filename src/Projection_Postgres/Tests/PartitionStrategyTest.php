<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Ultra\Projection\StoredEvent;
use Ultra\Projection_Postgres\PartitionStrategy;

test('domain-id partition strategy produces a stable composite key', function () {
    $strategy = PartitionStrategy::byDomainIds('studentId', 'courseId');
    $event = new StoredEvent(
        eventId: 42,
        eventType: 'student.subscribed',
        payload: [],
        domainIds: ['studentId' => 'student-1', 'courseId' => 'course-101'],
    );

    expect($strategy->keyFor($event))->toBe('{"courseId":"course-101","studentId":"student-1"}');
});
