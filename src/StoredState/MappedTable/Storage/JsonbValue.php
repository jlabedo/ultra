<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Storage;

use Ultra\MappedTable\JsonNull;

/** @internal The PHP representation of JSONB, shared by storage and the row buffer. */
final class JsonbValue
{
    /** Match JSON strings before numbers so strings and object keys are left intact. */
    private const string TOKENS = <<<'REGEX'
        ~"(?:[^"\\]++|\\.)*"|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?~
        REGEX;

    public static function copy(mixed $value): mixed
    {
        return JsonNull::Value === $value ? $value : self::document($value);
    }

    public static function encode(mixed $value): string
    {
        return self::json(JsonNull::Value === $value ? null : self::document($value, forEncoding: true));
    }

    public static function decode(string $json): mixed
    {
        try {
            $checked = preg_replace_callback(
                self::TOKENS,
                static fn(array $match): string => '"' === $match[0][0] ? $match[0] : self::checkedNumber($match[0]),
                $json,
            ) ?? throw new \InvalidArgumentException('Could not inspect JSONB numbers.');
            $value = json_decode($checked, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Invalid JSONB document.', previous: $exception);
        }

        return null === $value ? JsonNull::Value : self::document($value);
    }

    private static function checkedNumber(string $number): string
    {
        $decoded = json_decode($number, flags: JSON_THROW_ON_ERROR);
        if (
            !is_float($decoded)
            || is_finite($decoded) && self::decimal($number) === self::decimal(self::json($decoded))
        ) {
            return $number;
        }

        // PostgreSQL can print an exact PHP integer with a fractional scale, e.g. PHP_INT_MAX followed by .00.
        [$digits, $power] = array_pad(explode('e', self::decimal($number), 2), 2, '0');
        $zeros = (int) $power;
        if ($zeros >= 0 && $zeros <= 19 && (strlen(ltrim($digits, '-')) + $zeros) <= 19) {
            $integer = $digits . str_repeat('0', $zeros);
            if (false !== filter_var($integer, FILTER_VALIDATE_INT)) {
                return $integer;
            }
        }

        throw new \InvalidArgumentException('A JSONB number loses precision in PHP.');
    }

    public static function equivalent(mixed $left, mixed $right): bool
    {
        if (null === $left || null === $right || JsonNull::Value === $left || JsonNull::Value === $right) {
            return $left === $right;
        }

        return self::comparison($left) === self::comparison($right);
    }

    private static function comparison(mixed $value): string
    {
        $json = self::json(self::document($value, forEncoding: true, sortObjects: true));

        return (
            preg_replace_callback(
                self::TOKENS,
                static fn(array $match): string => '"' === $match[0][0] ? $match[0] : self::decimal($match[0]),
                $json,
            ) ?? throw new \LogicException('Could not compare JSONB numbers.')
        );
    }

    /** Compare decimal values without converting either spelling to a floating-point number. */
    private static function decimal(string $number): string
    {
        [$mantissa, $exponent] = array_pad(explode('e', strtolower($number), 2), 2, '0');
        $negative = str_starts_with($mantissa, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($mantissa, '-'), 2), 2, '');
        $digits = ltrim($integer . $fraction, '0');
        if ('' === $digits) {
            return '0';
        }

        $significant = rtrim($digits, '0');
        $power = (int) $exponent - strlen($fraction) + strlen($digits) - strlen($significant);

        return ($negative ? '-' : '') . $significant . 'e' . $power;
    }

    private static function document(
        mixed $value,
        int $depth = 0,
        bool $forEncoding = false,
        bool $sortObjects = false,
    ): mixed {
        if ($depth >= 511) {
            throw new \InvalidArgumentException('JSONB document is recursive or exceeds the supported nesting depth.');
        }
        if (null === $value || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value)) {
            if (json_decode(self::json($value), flags: JSON_THROW_ON_ERROR) !== $value) {
                throw new \InvalidArgumentException(
                    'JSONB number loses precision with the PHP serialization settings.',
                );
            }

            return $value;
        }
        if (is_string($value)) {
            self::assertString($value);
            return $value;
        }
        if (!is_array($value) && !$value instanceof \stdClass) {
            throw new \InvalidArgumentException('JSONB expects a PHP JSON value.');
        }

        $isObject = $value instanceof \stdClass || !array_is_list($value);
        $entries = $value instanceof \stdClass ? get_object_vars($value) : $value;
        if ($isObject && $sortObjects) {
            ksort($entries, SORT_STRING);
        }

        $document = [];
        foreach ($entries as $key => $entry) {
            if ($isObject && is_string($key)) {
                self::assertString($key);
            }
            $document[$key] = self::document($entry, $depth + 1, $forEncoding, $sortObjects);
        }

        return $isObject && ($forEncoding || self::hasListKeys($document)) ? (object) $document : $document;
    }

    /** @param array<array-key, mixed> $object */
    private static function hasListKeys(array $object): bool
    {
        $size = count($object);
        foreach ($object as $key => $_) {
            if (!is_int($key) || $key < 0 || $key >= $size) {
                return false;
            }
        }

        return true;
    }

    private static function assertString(string $value): void
    {
        if (str_contains($value, "\0") || 1 !== preg_match('//u', $value)) {
            throw new \InvalidArgumentException('JSONB strings must be valid UTF-8 without NUL.');
        }
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
