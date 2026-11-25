<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Storage\JsonbValue;

/** @internal Wire codecs selected by the mapped row set compiler. */
enum PostgresMappedColumnType: string
{
    case Uuid = 'UUID';
    case TextArray = 'TEXT[]';
    case UuidArray = 'UUID[]';
    case IntArray = 'INTEGER[]';
    case Jsonb = 'JSONB';
    case Date = 'DATE';
    case Timestamp = 'TIMESTAMP(6) WITHOUT TIME ZONE';
    case TimestampWithTimeZone = 'TIMESTAMP(6) WITH TIME ZONE';

    public function phpType(): string
    {
        return match ($this) {
            self::TextArray, self::UuidArray, self::IntArray => 'array',
            self::Jsonb => 'JSON',
            default => 'string',
        };
    }

    public function normalize(mixed $value, int $timestampPrecision, bool $preserveTimestampOffset): mixed
    {
        if (null === $value) {
            return null;
        }

        return match ($this) {
            self::Jsonb => JsonbValue::copy($value),
            self::TextArray, self::UuidArray, self::IntArray => $this->normalizeArray($value),
            default => $this->normalizeString($value, $timestampPrecision, $preserveTimestampOffset),
        };
    }

    public function encode(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        return match ($this) {
            self::Jsonb => JsonbValue::encode($value),
            self::TextArray, self::UuidArray, self::IntArray => $this->encodeArray($this->normalizeArray($value)),
            default => $this->normalizeString($value, 6),
        };
    }

    public function decode(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('PostgreSQL %s expects a database string.', $this->value));
        }

        return match ($this) {
            self::Jsonb => JsonbValue::decode($value),
            self::TextArray, self::UuidArray => $this->normalizeArray(self::decodeArray($value)),
            self::IntArray => array_map(static fn(?string $item): ?int => null === $item
                ? null
                : (int) $item, self::decodeArray($value)),
            default => $this->normalizeString($value, 6, preserveTimestampOffset: true),
        };
    }

    private function normalizeString(mixed $value, int $precision, bool $preserveTimestampOffset = false): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('PostgreSQL %s expects a PHP string.', $this->value));
        }

        return match ($this) {
            self::Uuid => self::uuid($value),
            self::Date => self::date($value),
            self::Timestamp, self::TimestampWithTimeZone => $this->timestamp(
                $value,
                $precision,
                $preserveTimestampOffset,
            ),
            default => $value,
        };
    }

    private static function uuid(string $value): string
    {
        $pattern = '/^(?:[0-9a-f]{4}-?){7}[0-9a-f]{4}$/iD';
        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            $value = substr($value, 1, -1);
        }
        if (1 !== preg_match($pattern, $value)) {
            throw new \InvalidArgumentException('Mapped UUID must use a valid hexadecimal UUID spelling.');
        }

        $digits = strtolower(str_replace('-', '', $value));

        return (
            substr($digits, 0, 8)
            . '-'
            . substr($digits, 8, 4)
            . '-'
            . substr($digits, 12, 4)
            . '-'
            . substr($digits, 16, 4)
            . '-'
            . substr($digits, 20)
        );
    }

    private static function date(string $value): string
    {
        $parts = [];
        if (
            1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            throw new \InvalidArgumentException('Mapped DATE must be a valid YYYY-MM-DD string.');
        }

        return $value;
    }

    private function timestamp(string $value, int $precision, bool $preserveOffset): string
    {
        $parts = [];
        if (
            1 !== preg_match(
                '/^(\d{4}-\d{2}-\d{2})[T ]((?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d)(?:\.(\d{1,6}))?(Z|[+-](?:0\d|1[0-5])(?::?[0-5]\d)?(?::[0-5]\d)?)?$/iD',
                $value,
                $parts,
            )
        ) {
            throw new \InvalidArgumentException(
                'Mapped timestamp must contain an ISO date and time with up to six fractional digits.',
            );
        }
        self::date($parts[1]);
        $utc = new \DateTimeZone('UTC');
        $offset = self::Timestamp === $this ? '' : $parts[4] ?? '';
        $seconds = new \DateTimeImmutable($parts[1] . 'T' . $parts[2] . $offset, $utc);
        $micros = (int) str_pad($parts[3] ?? '', 6, '0');
        if ($precision < 6) {
            $unit = 10 ** (6 - $precision);
            // PostgreSQL rounds halfway values away from its epoch (2000-01-01).
            $half = intdiv($unit, 2) - (int) ($seconds->getTimestamp() < 946_684_800);
            $micros = intdiv($micros + $half, $unit) * $unit;
            if (1_000_000 === $micros) {
                $seconds = $seconds->modify('+1 second');
                $micros = 0;
            }
        }

        if (!$preserveOffset || 0 !== ($seconds->getOffset() % 60)) {
            $seconds = $seconds->setTimezone($utc);
        }

        return sprintf('%s.%06d%s', $seconds->format('Y-m-d\TH:i:s'), $micros, $seconds->format('P'));
    }

    /** @return list<string|int|null> */
    private function normalizeArray(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('PostgreSQL %s expects a PHP list.', $this->value));
        }

        $items = [];
        foreach ($value as $item) {
            if (null === $item) {
                $items[] = null;
            } elseif (self::IntArray === $this && is_int($item)) {
                MappedColumn::assertInt32($item);
                $items[] = $item;
            } elseif (self::IntArray !== $this && is_string($item)) {
                MappedColumn::assertText($item);
                $items[] = self::UuidArray === $this ? self::uuid($item) : $item;
            } else {
                throw new \InvalidArgumentException(sprintf('Invalid element in PostgreSQL %s list.', $this->value));
            }
        }

        return $items;
    }

    /** @param list<string|int|null> $items */
    private function encodeArray(array $items): string
    {
        $encoded = array_map(static fn(string|int|null $item): string => match (true) {
            null === $item => 'NULL',
            is_int($item) => (string) $item,
            default => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $item) . '"',
        }, $items);

        return '{' . implode(',', $encoded) . '}';
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

            if (!$quoted && ('{' === $char || '}' === $char)) {
                throw new \InvalidArgumentException('Mapped PostgreSQL arrays must be one-dimensional.');
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
