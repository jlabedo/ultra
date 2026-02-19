<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Engine;

use Ultra\DecisionEngine\Attribute\BindTo;
use Ultra\DecisionEngine\Engine\CommandMetadataResolver;
use Ultra\DecisionEngine\Tests\Example\Command\CreateCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Command\SubscribeStudentToCourseCommand;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;
use Ultra\DecisionEngine\Tests\Example\State\StudentSubscriptionState;

test('resolves state parameters from SubscribeStudentToCourseCommand correctly', function () {
    $resolver = new CommandMetadataResolver();
    $meta = $resolver->resolve(SubscribeStudentToCourseCommand::class);

    expect($meta->decideMethod->getName())->toBe('decide');
    expect($meta->stateParameters)->toHaveCount(2);

    $courseParam = $meta->stateParameters[0];
    expect($courseParam->parameterName)->toBe('course');
    expect($courseParam->stateClass)->toBe(CourseCapacityState::class);
    expect($courseParam->domainIdentifierName)->toBe('course');
    expect($courseParam->commandProperty)->toBe('courseId');
    expect($courseParam->isNewStream)->toBeFalse();
    expect($courseParam->isNullable)->toBeFalse();

    $studentParam = $meta->stateParameters[1];
    expect($studentParam->parameterName)->toBe('student');
    expect($studentParam->stateClass)->toBe(StudentSubscriptionState::class);
    expect($studentParam->domainIdentifierName)->toBe('student');
    expect($studentParam->commandProperty)->toBe('studentId');
});

test('resolves check method when present', function () {
    $resolver = new CommandMetadataResolver();
    $meta = $resolver->resolve(SubscribeStudentToCourseCommand::class);

    expect($meta->checkMethod)->not->toBeNull();
    expect($meta->checkMethod->getName())->toBe('check');
});

test('check method is null when absent', function () {
    $resolver = new CommandMetadataResolver();
    $meta = $resolver->resolve(CreateCourseCommand::class);

    expect($meta->checkMethod)->toBeNull();
});

test('detects NewStream on CreateCourseCommand', function () {
    $resolver = new CommandMetadataResolver();
    $meta = $resolver->resolve(CreateCourseCommand::class);

    expect($meta->stateParameters)->toHaveCount(1);
    expect($meta->stateParameters[0]->isNewStream)->toBeTrue();
    expect($meta->stateParameters[0]->isNullable)->toBeTrue();
});

test('detects nullable state parameter', function () {
    $resolver = new CommandMetadataResolver();
    $meta = $resolver->resolve(CreateCourseCommand::class);

    expect($meta->stateParameters[0]->isNullable)->toBeTrue();
});

test('identifies service parameters', function () {
    $resolver = new CommandMetadataResolver();
    $meta = $resolver->resolve(CommandWithServiceDep::class);

    expect($meta->serviceParameters)->toHaveCount(1);
    expect($meta->serviceParameters[0]->parameterName)->toBe('clock');
    expect($meta->serviceParameters[0]->serviceClass)->toBe(\DateTimeInterface::class);
});

test('resolves BindTo override', function () {
    $resolver = new CommandMetadataResolver();
    $meta = $resolver->resolve(CommandWithBindTo::class);

    expect($meta->stateParameters)->toHaveCount(3);
    expect($meta->stateParameters[0]->commandProperty)->toBe('fromCourseId');
    expect($meta->stateParameters[1]->commandProperty)->toBe('toCourseId');
    expect($meta->stateParameters[2]->commandProperty)->toBe('studentId');
});

test('throws on missing command property', function () {
    $resolver = new CommandMetadataResolver();

    expect(fn () => $resolver->resolve(CommandMissingProperty::class))
        ->toThrow(\LogicException::class, 'no property "courseId"');
});

/** Test double: command with service dependency */
final class CommandWithServiceDep
{
    public function __construct(
        public string $courseId,
    ) {}

    public function decide(
        \Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState $course,
        \DateTimeInterface $clock,
    ): array {
        return [];
    }
}

/** Test double: command with BindTo */
final class CommandWithBindTo
{
    public function __construct(
        public string $fromCourseId,
        public string $toCourseId,
        public string $studentId,
    ) {}

    public function decide(
        #[BindTo('fromCourseId')] CourseCapacityState $fromCourse,
        #[BindTo('toCourseId')] CourseCapacityState $toCourse,
        StudentSubscriptionState $student,
    ): array {
        return [];
    }
}

/** Test double: command missing courseId property */
final class CommandMissingProperty
{
    public function decide(CourseCapacityState $course): array
    {
        return [];
    }
}
