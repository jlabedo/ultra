<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class PostgresMappedColumnType
{
    private const TEXT = 'text';
    private const ARRAY_TEXT = 'array_text';
    private const ARRAY_INT = 'array_int';
    private const JSON = 'json';
    private const DATE = 'date';

    private function __construct(
        public string $sqlType,
        private string $kind,
    ) {}

    /**
     * A scalar text column. Encodes/decodes as a plain string passthrough; its
     * value is what makes the default (untyped) column behaviour explicit when
     * building unnest()-based bulk inserts.
     */
    public static function text(): self
    {
        return new self('TEXT', self::TEXT);
    }

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

    public static function jsonb(): self
    {
        return new self('JSONB', self::JSON);
    }

    public static function date(): self
    {
        return new self('DATE', self::DATE);
    }

    public function encode(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        if (self::TEXT === $this->kind) {
            return self::encodeText($value);
        }

        if (self::DATE === $this->kind) {
            return self::encodeDate($value);
        }

        if (self::JSON === $this->kind) {
            return self::encodeJson($value);
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

        if (self::TEXT === $this->kind) {
            return $value;
        }

        if (self::DATE === $this->kind) {
            return self::decodeDate($value);
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Postgres column type "%s" expects a database string or null.', $this->sqlType));
        }

        if (self::JSON === $this->kind) {
            return self::decodeJson($value);
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
     * SQL cast for a one-dimensional Postgres array whose elements have this
     * column's type, e.g. DATE -> "DATE[]". Used by unnest()-based bulk inserts
     * so the bound-parameter count stays constant (one array per column).
     */
    public function arraySqlType(): string
    {
        if (self::ARRAY_TEXT === $this->kind || self::ARRAY_INT === $this->kind) {
            throw new \LogicException(sprintf(
                'Postgres column type "%s" is itself an array and cannot be bulk-inserted through unnest().',
                $this->sqlType,
            ));
        }

        return $this->sqlType.'[]';
    }

    /**
     * Encode a list of scalar values of this column's type into a Postgres
     * array literal suitable for `unnest(:param::<type>[])`. Nulls serialize as
     * NULL elements (not empty strings), preserving nullable-column semantics.
     *
     * @param list<mixed> $values
     */
    public function encodeArrayLiteral(array $values): string
    {
        return match ($this->kind) {
            self::TEXT => self::encodeTextArray($values),
            self::DATE => self::encodeTextArray(array_map(
                static fn (mixed $value): ?string => null === $value ? null : self::encodeDate($value),
                $values,
            )),
            self::JSON => self::encodeTextArray(array_map(
                static fn (mixed $value): ?string => null === $value ? null : self::encodeJson($value),
                $values,
            )),
            default => throw new \LogicException(sprintf(
                'Postgres column type "%s" cannot be encoded as a scalar unnest() array.',
                $this->sqlType,
            )),
        };
    }

    private static function encodeText(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException('Postgres TEXT column value must be a string, int, or null.');
        }

        return (string) $value;
    }

    private static function encodeDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException('Postgres DATE column value must be a YYYY-MM-DD string, DateTimeInterface, or null.');
        }

        return $value;
    }

    private static function decodeDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Postgres DATE column value must decode from a database string, DateTimeInterface, or null.');
        }

        return substr($value, 0, 10);
    }

    private static function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Postgres JSONB column value must be JSON encodable.', previous: $e);
        }
    }

    private static function decodeJson(string $value): mixed
    {
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Postgres JSONB column value must contain valid JSON.', previous: $e);
        }
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
