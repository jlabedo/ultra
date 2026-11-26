<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\Sql\SqlType;

/** @internal Column metadata and value semantics for a compiled row set. */
final readonly class MappedColumn
{
    public string $type;
    public ?string $keyType;

    /** @param ColumnCodec|class-string<ColumnCodec> $codec Symfony supplies a class during compilation. */
    public function __construct(
        public SqlType $sqlType,
        public bool $nullable,
        public ColumnCodec|string $codec,
    ) {
        $this->type = $codec::representation();
        $this->keyType = $codec::supportsKeys() ? $this->type : null;
        if (null !== $this->keyType && !in_array($this->keyType, ['int', 'string'], true)) {
            throw new \InvalidArgumentException('A codec supporting keys must use an int or string representation.');
        }
    }

    public function normalize(mixed $value): mixed
    {
        if (null !== $value) {
            return $this->checkedValue($this->boundCodec()->normalize($this->sqlType, $value));
        }
        if (!$this->nullable) {
            throw new \InvalidArgumentException('This column is not nullable.');
        }

        return null;
    }

    public function key(mixed $value): int|string
    {
        $key = $this->boundCodec()->key($this->sqlType, $value);
        if (get_debug_type($key) !== $this->keyType) {
            throw new \InvalidArgumentException('The codec returned a key that does not match its declared type.');
        }

        return $key;
    }

    /** Detach mutable values without changing the representation of scalar reads. */
    public function copy(mixed $value): mixed
    {
        return is_scalar($value) ? $value : $this->normalize($value);
    }

    public function equivalent(mixed $left, mixed $right): bool
    {
        return null === $left || null === $right
            ? $left === $right
            : $this->boundCodec()->equivalent($this->sqlType, $left, $right);
    }

    public function encode(mixed $value): int|string|float|bool|null
    {
        return null === $value ? null : $this->boundCodec()->encode($this->sqlType, $value);
    }

    public function decode(mixed $value): mixed
    {
        return null === $value
            ? $this->normalize(null)
            : $this->checkedValue($this->boundCodec()->decode($this->sqlType, $value));
    }

    private function checkedValue(int|string|float|bool|array|\stdClass|JsonNull $value): mixed
    {
        if ('JSON' !== $this->type && get_debug_type($value) !== $this->type) {
            throw new \UnexpectedValueException(sprintf(
                'Column codec declares %s but returned %s.',
                $this->type,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private function boundCodec(): ColumnCodec
    {
        return $this->codec instanceof ColumnCodec
            ? $this->codec
            : throw new \LogicException('A mapped column must be bound to its codec before use.');
    }
}
