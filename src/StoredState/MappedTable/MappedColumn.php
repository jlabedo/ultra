<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Codec\IntegerCodec;
use Ultra\MappedTable\Codec\ScalarCodec;

/** @internal Column metadata and value semantics for a compiled row set. */
final readonly class MappedColumn
{
    public ?string $type;
    public ?string $keyType;

    /** @param ColumnCodec|class-string<ColumnCodec> $codec Symfony supplies a class during compilation. */
    public function __construct(
        public ?bool $nullable = null,
        public ColumnCodec|string $codec = new ScalarCodec(),
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
            return $this->checkedValue($this->boundCodec()->normalize($value));
        }
        if (false === $this->nullable) {
            throw new \InvalidArgumentException('This column is not nullable.');
        }

        return null;
    }

    public function key(mixed $value): int|string
    {
        $key = $this->boundCodec()->key($value);
        if (null !== $this->keyType && get_debug_type($key) !== $this->keyType) {
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
        return null === $left || null === $right ? $left === $right : $this->boundCodec()->equivalent($left, $right);
    }

    public function increment(mixed $value, int $delta): int
    {
        $codec = $this->codec;
        if (!$codec instanceof IntegerCodec) {
            throw new \LogicException('An increment requires the built-in integer codec.');
        }

        return $codec->normalize(IntegerCodec::add($value, $delta));
    }

    public function encode(mixed $value): int|string|float|bool|null
    {
        return null === $value ? null : $this->boundCodec()->encode($value);
    }

    public function decode(mixed $value): mixed
    {
        if (null === $value) {
            return $this->normalize($value);
        }

        $codec = $this->boundCodec();
        $decoded = $this->checkedValue($codec->decode($value));

        return is_scalar($decoded) || $codec->decodesDetachedValues() ? $decoded : $this->normalize($decoded);
    }

    private function checkedValue(int|string|float|bool|array|\stdClass|JsonNull $value): mixed
    {
        if (null !== $this->type && 'JSON' !== $this->type && get_debug_type($value) !== $this->type) {
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
