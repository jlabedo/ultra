<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Tests;

use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Tests\Example\Command\SubscribeStudentToCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;
use Ultra\DecisionEngine\Tests\Example\State\StudentSubscriptionState;

test('decide success case', function () {
    $command = new SubscribeStudentToCourseCommand('math-101', 'alice');

    $course = new CourseCapacityState();
    $course->onCourseCreated(new CourseCreated('math-101', 'Math', maxCapacity: 10));
    $student = new StudentSubscriptionState();

    $events = $command->decide($course, $student);

    expect($events)->toEqual([new StudentSubscribed('math-101', 'alice')]);
});

test('decide throws on full course', function () {
    $command = new SubscribeStudentToCourseCommand('math-101', 'bob');

    $course = new CourseCapacityState();
    $course->onCourseCreated(new CourseCreated('math-101', 'Math', maxCapacity: 1));
    $course->onStudentSubscribed(new StudentSubscribed('math-101', 'alice'));
    $student = new StudentSubscriptionState();

    expect(fn () => $command->decide($course, $student))->toThrow(InvariantViolation::class);
});

test('decide throws on duplicate subscription', function () {
    $command = new SubscribeStudentToCourseCommand('math-101', 'alice');

    $course = new CourseCapacityState();
    $course->onCourseCreated(new CourseCreated('math-101', 'Math', maxCapacity: 10));
    $student = new StudentSubscriptionState();
    $student->onStudentSubscribed(new StudentSubscribed('math-101', 'alice'));

    expect(fn () => $command->decide($course, $student))->toThrow(InvariantViolation::class);
});

test('check returns empty array on valid state', function () {
    $command = new SubscribeStudentToCourseCommand('math-101', 'alice');

    $course = new CourseCapacityState();
    $course->onCourseCreated(new CourseCreated('math-101', 'Math', maxCapacity: 10));
    $student = new StudentSubscriptionState();

    $violations = $command->check($course, $student);

    expect($violations)->toBeArray();
    expect($violations)->toHaveCount(0);
});

test('check returns violations on invalid state', function () {
    $command = new SubscribeStudentToCourseCommand('math-101', 'alice');

    $course = new CourseCapacityState();
    $course->onCourseCreated(new CourseCreated('math-101', 'Math', maxCapacity: 1));
    $course->onStudentSubscribed(new StudentSubscribed('math-101', 'bob'));
    $student = new StudentSubscriptionState();

    $violations = $command->check($course, $student);

    expect($violations)->toHaveCount(1);
    expect($violations[0]->code)->toBe('course.full');
});

test('check returns course.not_found when the course state has no creation event', function () {
    $command = new SubscribeStudentToCourseCommand('math-101', 'alice');

    $course = new CourseCapacityState();
    $student = new StudentSubscriptionState();

    $violations = $command->check($course, $student);

    expect($violations)->toHaveCount(1);
    expect($violations[0]->code)->toBe('course.not_found');
});

test('decide calls check internally - violations from check match exception from decide', function () {
    $command = new SubscribeStudentToCourseCommand('math-101', 'alice');

    $course = new CourseCapacityState();
    $course->onCourseCreated(new CourseCreated('math-101', 'Math', maxCapacity: 1));
    $course->onStudentSubscribed(new StudentSubscribed('math-101', 'bob'));
    $student = new StudentSubscriptionState();

    $violationsFromCheck = $command->check($course, $student);

    try {
        $command->decide($course, $student);
    } catch (InvariantViolation $e) {
        expect($e->violations)->toEqual($violationsFromCheck);

        return;
    }

    expect(false)->toBeTrue('Expected InvariantViolation to be thrown');
});
