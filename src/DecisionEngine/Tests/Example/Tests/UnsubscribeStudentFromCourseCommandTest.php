<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Tests;

use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Tests\Example\Command\UnsubscribeStudentFromCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;
use Ultra\DecisionEngine\Tests\Example\State\StudentSubscriptionState;

test('decide success case', function () {
    $command = new UnsubscribeStudentFromCourseCommand('math-101', 'alice');

    $course = CourseCapacityState::fromEvents([
        new CourseCreated('math-101', 'Math', maxCapacity: 10),
        new StudentSubscribed('math-101', 'alice'),
    ]);
    $student = StudentSubscriptionState::fromEvents([
        new StudentSubscribed('math-101', 'alice'),
    ]);

    $events = $command->decide($course, $student);

    expect($events)->toEqual([new StudentUnsubscribed('math-101', 'alice')]);
});

test('decide throws when student not subscribed', function () {
    $command = new UnsubscribeStudentFromCourseCommand('math-101', 'alice');

    $course = CourseCapacityState::fromEvents([
        new CourseCreated('math-101', 'Math', maxCapacity: 10),
    ]);
    $student = StudentSubscriptionState::fromEvents([]);

    expect(fn () => $command->decide($course, $student))->toThrow(InvariantViolation::class);
});

test('check returns violation when not subscribed', function () {
    $command = new UnsubscribeStudentFromCourseCommand('math-101', 'alice');

    $course = CourseCapacityState::fromEvents([
        new CourseCreated('math-101', 'Math', maxCapacity: 10),
    ]);
    $student = StudentSubscriptionState::fromEvents([]);

    $violations = $command->check($course, $student);

    expect($violations)->toHaveCount(1);
    expect($violations[0]->code)->toBe('student.not_subscribed');
});
