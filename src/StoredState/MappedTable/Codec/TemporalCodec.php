<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Codec;

use Ultra\MappedTable\ColumnCodec;

final class TemporalCodec extends ColumnCodec
{
    public const string DATE = 'Y-m-d';
    public const string LOCAL_TIMESTAMP = 'Y-m-d H:i:s.u';
    public const string OFFSET_TIMESTAMP = 'Y-m-d H:i:s.uP';

    public function __construct(public readonly string $format = self::OFFSET_TIMESTAMP) {}

    public static function representation(): string
    {
        return 'string';
    }

    public function normalize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($this->format);
        }

        return is_string($value)
            ? $value
            : throw new \InvalidArgumentException('Expected a date string or DateTimeInterface.');
    }

    public function equivalent(mixed $left, mixed $right): bool
    {
        return (
            $left === $right
            || self::DATE !== $this->format
            && is_string($left)
            && is_string($right)
            && null !== ($canonical = $this->comparisonString($left))
            && $canonical === $this->comparisonString($right)
        );
    }

    private function comparisonString(string $value): ?string
    {
        // Recognize ISO timestamps, including the offset spellings PostgreSQL returns in ISO DateStyle.
        $parts = [];
        $pattern = '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:0\d|1[0-5])(?::?[0-5]\d)?(?::[0-5]\d)?)?$/D';
        if (1 !== preg_match($pattern, $value, $parts)) {
            return null;
        }

        $local = $parts[1] . ' ' . $parts[2] . '.' . str_pad($parts[3] ?? '', 6, '0');
        if (self::LOCAL_TIMESTAMP === $this->format) {
            return $local;
        }
        if (!isset($parts[4])) {
            return null;
        }

        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.uP', $local . $parts[4]);

        return false !== $instant && false === \DateTimeImmutable::getLastErrors() ? $instant->format('U.u') : null;
    }
}
