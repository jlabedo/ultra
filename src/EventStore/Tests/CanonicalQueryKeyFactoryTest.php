<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use Ultra\EventStore\CanonicalQueryKeyFactory;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\Exception\UnsupportedQueryException;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\EventStore\StreamQuery;

test('keys for event generate canonical singletons and pairs only', function () {
    $factory = new CanonicalQueryKeyFactory(new StaticEventTopologyProvider([
        'student.subscribed' => new EventTopology(['facultyId', 'courseId', 'studentId'], [
            'courseId' => ['facultyId'],
        ]),
    ]));

    $keys = $factory->keysForEvent('student.subscribed', [
        'facultyId' => 'faculty-1',
        'courseId' => 'course-123',
        'studentId' => 'student-1',
    ]);

    expect($keys)->toHaveCount(5);
    expect($keys)->toContain("student.subscribed\0courseId\0course-123");
    expect($keys)->toContain("student.subscribed\0facultyId\0faculty-1");
    expect($keys)->toContain("student.subscribed\0studentId\0student-1");
    expect($keys)->toContain("student.subscribed\0courseId\0course-123\0studentId\0student-1");
    expect($keys)->toContain("student.subscribed\0facultyId\0faculty-1\0studentId\0student-1");
});

test('resolving non canonical pair throws', function () {
    $factory = new CanonicalQueryKeyFactory(new StaticEventTopologyProvider([
        'student.subscribed' => new EventTopology(['facultyId', 'courseId', 'studentId'], [
            'courseId' => ['facultyId'],
        ]),
    ]));

    $factory->resolveQuery(new StreamQuery(
        ['facultyId' => 'faculty-1', 'courseId' => 'course-123'],
        ['student.subscribed'],
    ));
})->throws(UnsupportedQueryException::class);
