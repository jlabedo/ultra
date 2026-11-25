<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Storage\JsonbValue;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\PostgresColumnType;

/** @internal The PHP representation and value semantics inferred for a schema column. */
final readonly class MappedColumn
{
    public function __construct(
        public string $type,
        public bool $nullable = false,
        public ?PostgresMappedColumnType $codec = null,
        public int $timestampPrecision = 6,
        public bool $int32 = false,
    ) {}

    public static function fromSchema(ColumnDefinition $column, string $table): self
    {
        $sql = PostgresColumnType::canonical($column->type);
        $codec = match ($sql) {
            'uuid' => PostgresMappedColumnType::Uuid,
            'date' => PostgresMappedColumnType::Date,
            'jsonb' => PostgresMappedColumnType::Jsonb,
            'text[]' => PostgresMappedColumnType::TextArray,
            'uuid[]' => PostgresMappedColumnType::UuidArray,
            'integer[]' => PostgresMappedColumnType::IntArray,
            default => null,
        };
        $precision = 6;
        if (null !== $sql && str_starts_with($sql, 'timestamp(')) {
            $precision = (int) $sql[10];
            $codec = str_ends_with($sql, ' with time zone')
                ? PostgresMappedColumnType::TimestampWithTimeZone
                : PostgresMappedColumnType::Timestamp;
        }
        $type = match ($sql) {
            'integer', 'bigint' => 'int',
            'boolean' => 'bool',
            'text' => 'string',
            default => $codec?->phpType() ?? throw new \LogicException(sprintf(
                'Unsupported SQL type "%s" for column "%s" on mapped schema table "%s".',
                PostgresColumnType::sql($column->type),
                $column->id,
                $table,
            )),
        };

        return new self($type, $column->nullable, $codec, $precision, 'integer' === $sql);
    }

    public function accepts(mixed $value): bool
    {
        return null === $value ? $this->nullable : 'JSON' === $this->type || get_debug_type($value) === $this->type;
    }

    public function normalize(mixed $value, bool $preserveTimestampOffset = false): mixed
    {
        if (null === $value) {
            return null;
        }
        if (null !== $this->codec) {
            return $this->codec->normalize($value, $this->timestampPrecision, $preserveTimestampOffset);
        }
        if ($this->int32 && is_int($value)) {
            self::assertInt32($value);
        }
        if (is_string($value)) {
            self::assertText($value);
        }

        return $value;
    }

    public function equivalent(mixed $left, mixed $right): bool
    {
        if (null === $this->codec) {
            return $left === $right;
        }

        return PostgresMappedColumnType::Jsonb === $this->codec
            ? JsonbValue::equivalent($left, $right)
            : $this->normalize($left) === $this->normalize($right);
    }

    public static function assertInt32(int $value): void
    {
        if ($value < -2_147_483_648 || $value > 2_147_483_647) {
            throw new \InvalidArgumentException('Mapped integer is outside the PostgreSQL integer range.');
        }
    }

    public static function assertText(string $value): void
    {
        if (str_contains($value, "\0") || 1 !== preg_match('//u', $value)) {
            throw new \InvalidArgumentException('Mapped text must be valid UTF-8 without NUL.');
        }
    }
}
