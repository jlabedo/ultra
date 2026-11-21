<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

enum PostgresMappedColumnType: string
{
    case Text = 'TEXT';
    case TextArray = 'TEXT[]';
    case UuidArray = 'UUID[]';
    case IntArray = 'INTEGER[]';
    case Jsonb = 'JSONB';
    case Date = 'DATE';
    case Timestamp = 'TIMESTAMP(6) WITHOUT TIME ZONE';
    case TimestampWithTimeZone = 'TIMESTAMP(6) WITH TIME ZONE';

    /** @internal Validates the declared PHP representation before a codec is wired into a row set. */
    public function assertSupports(string $column, MappedColumn $mapping): void
    {
        $type = match ($this) {
            self::TextArray, self::UuidArray, self::IntArray => 'array',
            self::Text, self::Date, self::Timestamp, self::TimestampWithTimeZone => 'string',
            self::Jsonb => $mapping->type,
        };
        if ($mapping->type !== $type) {
            throw new \LogicException(sprintf(
                'PostgreSQL codec "%s" for column "%s" expects PHP %s, declared %s.',
                $this->value,
                $column,
                $type,
                $mapping->type,
            ));
        }
    }

    public function encode(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        return match ($this) {
            self::Text => self::encodeText($value),
            self::Date => self::encodeDate($value),
            self::Timestamp, self::TimestampWithTimeZone => self::encodeTimestamp($value),
            self::Jsonb => self::encodeJson($value),
            self::TextArray, self::UuidArray, self::IntArray => $this->encodeArray($value),
        };
    }

    public function decode(mixed $value): mixed
    {
        if (null === $value || is_array($value) || self::Text === $this) {
            return $value;
        }
        if (self::Date === $this) {
            return self::decodeDate($value);
        }
        if (self::Timestamp === $this || self::TimestampWithTimeZone === $this) {
            return new \DateTimeImmutable(self::encodeTimestamp($value), new \DateTimeZone('UTC'))->format(
                'Y-m-d\TH:i:s.uP',
            );
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Postgres column type "%s" expects a database string or null.',
                $this->value,
            ));
        }
        if (self::Jsonb === $this) {
            return self::decodeJson($value);
        }

        $values = self::decodeArray($value);

        return self::IntArray === $this
            ? array_map(static fn(?string $item): ?int => null === $item ? null : (int) $item, $values)
            : $values;
    }

    private function encodeArray(mixed $value): string
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Postgres column type "%s" expects a PHP array or null.',
                $this->value,
            ));
        }

        return self::IntArray === $this ? self::encodeIntArray($value) : self::encodeTextArray($value);
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
            throw new \InvalidArgumentException(
                'Postgres DATE column value must be a YYYY-MM-DD string, DateTimeInterface, or null.',
            );
        }

        return $value;
    }

    private static function encodeTimestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Postgres TIMESTAMP column value must be a string, DateTimeInterface, or null.',
            );
        }

        return $value;
    }

    private static function decodeDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Postgres DATE column value must decode from a database string, DateTimeInterface, or null.',
            );
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
        return '{' . implode(',', array_map(static function (mixed $value): string {
            if (null === $value) {
                return 'NULL';
            }

            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException('Postgres text array values must be strings, ints, or null.');
            }

            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value) . '"';
        }, array_values($values))) . '}';
    }

    /**
     * @param array<mixed> $values
     */
    private static function encodeIntArray(array $values): string
    {
        return '{' . implode(',', array_map(static function (mixed $value): string {
            if (null === $value) {
                return 'NULL';
            }

            if (!is_int($value)) {
                throw new \InvalidArgumentException('Postgres integer array values must be ints or null.');
            }

            return (string) $value;
        }, array_values($values))) . '}';
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
