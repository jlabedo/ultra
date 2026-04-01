<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Tests;

use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;

test('new state reports that the course does not exist yet', function () {
    $state = new CourseCapacityState();

    expect($state->exists())->toBeFalse();
    expect($state->isFull())->toBeFalse();
});

test('handlers correctly track capacity and count', function () {
    $state = new CourseCapacityState();
    $state->onCourseCreated(new CourseCreated('math-101', 'Math', maxCapacity: 10));
    $state->onStudentSubscribed(new StudentSubscribed('math-101', 'alice'));
    $state->onStudentSubscribed(new StudentSubscribed('math-101', 'bob'));

    expect($state->exists())->toBeTrue();
    expect($state->isFull())->toBeFalse();
});

test('isFull returns true at capacity', function () {
    $state = new CourseCapacityState();
    $state->onCourseCreated(new CourseCreated('math-101', 'Math', maxCapacity: 2));
    $state->onStudentSubscribed(new StudentSubscribed('math-101', 'alice'));
    $state->onStudentSubscribed(new StudentSubscribed('math-101', 'bob'));

    expect($state->isFull())->toBeTrue();
});

test('isFull returns false after unsubscribe', function () {
    $state = new CourseCapacityState();
    $state->onCourseCreated(new CourseCreated('math-101', 'Math', maxCapacity: 1));
    $state->onStudentSubscribed(new StudentSubscribed('math-101', 'alice'));
    $state->onStudentUnsubscribed(new StudentUnsubscribed('math-101', 'alice'));

    expect($state->isFull())->toBeFalse();
});
