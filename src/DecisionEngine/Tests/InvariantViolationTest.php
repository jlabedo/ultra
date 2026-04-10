<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Violation;

/**
 * @internal
 *
 * @covers \Ultra\DecisionEngine\Exception\InvariantViolation
 */
final class InvariantViolationTest extends TestCase
{
    public function test_it_exposes_the_collected_violations_in_its_state_and_message(): void
    {
        $violations = [
            new Violation('course.full', 'Course has reached maximum capacity'),
            new Violation('student.already_subscribed', 'Student is already subscribed'),
        ];

        $exception = new InvariantViolation($violations);

        self::assertSame($violations, $exception->violations);
        self::assertStringContainsString('course.full', $exception->getMessage());
        self::assertStringContainsString('Course has reached maximum capacity', $exception->getMessage());
        self::assertStringContainsString('student.already_subscribed', $exception->getMessage());
        self::assertStringContainsString('Student is already subscribed', $exception->getMessage());
    }

    public function test_it_builds_an_empty_message_when_no_violations_are_present(): void
    {
        $exception = new InvariantViolation([]);

        self::assertSame([], $exception->violations);
        self::assertSame('', $exception->getMessage());
    }

    public function test_it_accepts_a_previous_throwable(): void
    {
        $previous = new \RuntimeException('inner');
        $exception = new InvariantViolation([new Violation('x', 'y')], $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
