<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Violation;

test('exposes violations', function () {
    $violations = [
        new Violation('course.full', 'Course has reached maximum capacity'),
        new Violation('student.already_subscribed', 'Student is already subscribed'),
    ];
    $e = new InvariantViolation($violations);

    expect($e->violations)->toBe($violations);
    expect($e->getMessage())->toContain('course.full');
    expect($e->getMessage())->toContain('Course has reached maximum capacity');
    expect($e->getMessage())->toContain('student.already_subscribed');
    expect($e->getMessage())->toContain('Student is already subscribed');
});

test('empty violations still builds message', function () {
    $e = new InvariantViolation([]);

    expect($e->violations)->toBe([]);
    expect($e->getMessage())->toBe('');
});

test('accepts previous throwable', function () {
    $previous = new \RuntimeException('inner');
    $e = new InvariantViolation([new Violation('x', 'y')], $previous);

    expect($e->getPrevious())->toBe($previous);
});
