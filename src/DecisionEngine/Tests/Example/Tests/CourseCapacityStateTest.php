<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Tests;

use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;

test('fromEvents correctly tracks capacity and count', function () {
    $state = CourseCapacityState::fromEvents([
        new CourseCreated('math-101', 'Math', maxCapacity: 10),
        new StudentSubscribed('math-101', 'alice'),
        new StudentSubscribed('math-101', 'bob'),
    ]);

    expect($state->isFull())->toBeFalse();
});

test('isFull returns true at capacity', function () {
    $state = CourseCapacityState::fromEvents([
        new CourseCreated('math-101', 'Math', maxCapacity: 2),
        new StudentSubscribed('math-101', 'alice'),
        new StudentSubscribed('math-101', 'bob'),
    ]);

    expect($state->isFull())->toBeTrue();
});

test('isFull returns false after unsubscribe', function () {
    $state = CourseCapacityState::fromEvents([
        new CourseCreated('math-101', 'Math', maxCapacity: 1),
        new StudentSubscribed('math-101', 'alice'),
        new StudentUnsubscribed('math-101', 'alice'),
    ]);

    expect($state->isFull())->toBeFalse();
});
