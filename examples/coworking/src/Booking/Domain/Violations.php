<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Violation;

final class Violations
{
    /**
     * @param list<Violation|null> $violations
     */
    public static function throwIfAny(array $violations): void
    {
        $violations = array_values(array_filter($violations));
        if ([] !== $violations) {
            throw new InvariantViolation($violations);
        }
    }
}
