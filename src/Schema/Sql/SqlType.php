<?php

declare(strict_types=1);

namespace Ultra\Sql;

/** Structured SQL identity resolved for PostgreSQL. */
final readonly class SqlType
{
    private function __construct(
        public string $sql,
        public ?ColumnType $family,
        public ?int $length = null,
    ) {}

    public static function resolve(ColumnType|self|string $type): self
    {
        return match (true) {
            $type instanceof self => $type,
            $type instanceof ColumnType => $type->sqlType(),
            default => self::fromSql($type),
        };
    }

    public static function of(ColumnType $family): self
    {
        return match ($family) {
            ColumnType::Timestamp, ColumnType::TimestampWithTimeZone => self::timestampType($family, 6),
            default => new self($family->value, $family),
        };
    }

    public static function fromSql(string $sql): self
    {
        if ('' === trim($sql)) {
            throw new \InvalidArgumentException('A PostgreSQL column type needs a SQL declaration.');
        }
        $canonical = preg_replace(['/\s+/', '/ *\[ *\]$/'], [' ', '[]'], strtolower(trim($sql)));
        assert(null !== $canonical, 'The type whitespace patterns are valid.');

        $parts = [];
        if (
            1 === preg_match(
                '/^(timestamp|timestamptz)(?: *\( *0*([0-6]) *\))?( with(?:out)? time zone)?$/D',
                $canonical,
                $parts,
            )
        ) {
            $alias = 'timestamptz' === $parts[1];
            if (!$alias || !isset($parts[3])) {
                $family = $alias || ' with time zone' === ($parts[3] ?? '')
                    ? ColumnType::TimestampWithTimeZone
                    : ColumnType::Timestamp;
                $precision = '' === ($parts[2] ?? '') ? 6 : (int) $parts[2];

                return self::timestampType($family, $precision);
            }
        }

        if (1 === preg_match('/^(?:varchar|character varying)(?: *\( *([0-9]+) *\))?$/D', $canonical, $parts)) {
            $length = isset($parts[1]) ? (int) $parts[1] : null;
            if (null !== $length && ($length < 1 || $length > 10_485_760)) {
                throw new \InvalidArgumentException('PostgreSQL varchar length must be between 1 and 10485760.');
            }

            return new self(
                null === $length ? 'varchar' : sprintf('varchar(%d)', $length),
                ColumnType::Varchar,
                $length,
            );
        }

        $family = match ($canonical) {
            'int', 'int4' => ColumnType::Int32,
            'int8' => ColumnType::Int64,
            'bool' => ColumnType::Boolean,
            'int[]', 'int4[]' => ColumnType::IntArray,
            default => ColumnType::tryFrom($canonical),
        };

        return null === $family ? new self($sql, null) : self::of($family);
    }

    public static function timestamp(int $precision = 6): self
    {
        return self::timestampType(ColumnType::Timestamp, $precision);
    }

    public static function timestampWithTimeZone(int $precision = 6): self
    {
        return self::timestampType(ColumnType::TimestampWithTimeZone, $precision);
    }

    private static function timestampType(ColumnType $family, int $precision): self
    {
        if ($precision < 0 || $precision > 6) {
            throw new \InvalidArgumentException('PostgreSQL timestamp precision must be between zero and six.');
        }

        $sql = 6 === $precision
            ? $family->value
            : sprintf(
                'timestamp(%d) %s time zone',
                $precision,
                ColumnType::TimestampWithTimeZone === $family ? 'with' : 'without',
            );

        return new self($sql, $family);
    }

    public function equivalentTo(self $other): bool
    {
        return $this->sql === $other->sql;
    }
}
