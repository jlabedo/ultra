<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Tests;

use Ultra\DecisionEngine\Tests\Example\Command\CreateCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;

test('decide returns CourseCreated when course does not exist yet', function () {
    $command = new CreateCourseCommand('math-101', 'Math', 10);
    $course = new CourseCapacityState();

    $events = $command->decide($course);

    expect($events)->toEqual([new CourseCreated('math-101', 'Math', 10)]);
});

test('decide throws when course already exists', function () {
    $command = new CreateCourseCommand('math-101', 'Math', 10);
    $course = new CourseCapacityState();
    $course->onCourseCreated(new CourseCreated('math-101', 'Existing', 5));

    expect(fn () => $command->decide($course))->toThrow(\InvalidArgumentException::class);
});
