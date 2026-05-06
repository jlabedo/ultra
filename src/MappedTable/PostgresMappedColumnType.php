<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class PostgresMappedColumnType
{
    private const ARRAY_TEXT = 'array_text';
    private const ARRAY_INT = 'array_int';

    private function __construct(
        public string $sqlType,
        private string $kind,
    ) {}

    public static function textArray(): self
    {
        return new self('TEXT[]', self::ARRAY_TEXT);
    }

    public static function uuidArray(): self
    {
        return new self('UUID[]', self::ARRAY_TEXT);
    }

    public static function intArray(): self
    {
        return new self('INTEGER[]', self::ARRAY_INT);
    }

    public function encode(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('Postgres column type "%s" expects a PHP array or null.', $this->sqlType));
        }

        return match ($this->kind) {
            self::ARRAY_TEXT => self::encodeTextArray($value),
            self::ARRAY_INT => self::encodeIntArray($value),
            default => throw new \LogicException(sprintf('Unsupported Postgres mapped column kind "%s".', $this->kind)),
        };
    }

    public function decode(mixed $value): mixed
    {
        if (null === $value || is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Postgres column type "%s" expects a database string or null.', $this->sqlType));
        }

        $values = self::decodeArray($value);

        return match ($this->kind) {
            self::ARRAY_TEXT => $values,
            self::ARRAY_INT => array_map(
                static fn (?string $item): ?int => null === $item ? null : (int) $item,
                $values,
            ),
            default => throw new \LogicException(sprintf('Unsupported Postgres mapped column kind "%s".', $this->kind)),
        };
    }

    /**
     * @param array<mixed> $values
     */
    private static function encodeTextArray(array $values): string
    {
        return '{'.implode(',', array_map(
            static function (mixed $value): string {
                if (null === $value) {
                    return 'NULL';
                }

                if (!is_string($value) && !is_int($value)) {
                    throw new \InvalidArgumentException('Postgres text array values must be strings, ints, or null.');
                }

                return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
            },
            array_values($values),
        )).'}';
    }

    /**
     * @param array<mixed> $values
     */
    private static function encodeIntArray(array $values): string
    {
        return '{'.implode(',', array_map(
            static function (mixed $value): string {
                if (null === $value) {
                    return 'NULL';
                }

                if (!is_int($value)) {
                    throw new \InvalidArgumentException('Postgres integer array values must be ints or null.');
                }

                return (string) $value;
            },
            array_values($values),
        )).'}';
    }

    /**
     * @return list<string|null>
     */
    private static function decodeArray(string $value): array
    {
        if ('{}' === $value) {
            return [];
        }

        if (!str_starts_with($value, '{') || !str_ends_with($value, '}')) {
            throw new \InvalidArgumentException(sprintf('Cannot decode Postgres array literal "%s".', $value));
        }

        $items = [];
        $buffer = '';
        $quoted = false;
        $escaped = false;
        $wasQuoted = false;
        $inner = substr($value, 1, -1);

        for ($i = 0, $length = strlen($inner); $i < $length; ++$i) {
            $char = $inner[$i];

            if ($escaped) {
                $buffer .= $char;
                $escaped = false;

                continue;
            }

            if ($quoted && '\\' === $char) {
                $escaped = true;

                continue;
            }

            if ('"' === $char) {
                $quoted = !$quoted;
                $wasQuoted = true;

                continue;
            }

            if (!$quoted && ',' === $char) {
                $items[] = !$wasQuoted && 'NULL' === $buffer ? null : $buffer;
                $buffer = '';
                $wasQuoted = false;

                continue;
            }

            $buffer .= $char;
        }

        if ($quoted || $escaped) {
            throw new \InvalidArgumentException(sprintf('Cannot decode Postgres array literal "%s".', $value));
        }

        $items[] = !$wasQuoted && 'NULL' === $buffer ? null : $buffer;

        return $items;
    }
}
