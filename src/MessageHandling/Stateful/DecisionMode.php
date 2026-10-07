<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;

/**
 * Message context switch for decision handlers.
 *
 * In check mode the decision handler runs with the same states and services, but its effects are discarded:
 * the send returns the violations of a thrown {@see InvariantViolation}, or an empty list.
 */
final class DecisionMode
{
    public const string CONTEXT_KEY = 'decision_mode';
    public const string CHECK = 'check';

    public static function isCheck(MessageContext $context): bool
    {
        return self::CHECK === $context->get(self::CONTEXT_KEY);
    }

    /**
     * Runs the decision in check mode and returns its violations as the result value.
     *
     * @param callable(): ProceedResult $decide
     */
    public static function check(callable $decide, MessageContext $context): ProceedResult
    {
        try {
            $result = $decide();
        } catch (InvariantViolation $violation) {
            return new ProceedResult($violation->violations, $context);
        }

        // Another decision aspect wrapping the same handler may already have turned violations into the value.
        $violations = is_array($result->value)
            ? array_values(array_filter($result->value, static fn(mixed $item): bool => $item instanceof Violation))
            : [];

        return new ProceedResult($violations, $result->context);
    }
}
