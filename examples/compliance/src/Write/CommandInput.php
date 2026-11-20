<?php

declare(strict_types=1);

namespace ComplianceExample\Write;

final class CommandInput
{
    public static function requireValues(string ...$values): void
    {
        foreach ($values as $value) {
            if ('' === trim($value) || str_contains($value, "\0")) {
                throw new \InvalidArgumentException(
                    'Identifiers and document types must be non-empty strings without NUL.',
                );
            }
        }
    }
}
