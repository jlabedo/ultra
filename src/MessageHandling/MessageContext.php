<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

/**
 * Immutable message metadata passed through the advice chain.
 *
 * The public bus APIs still accept arrays for ergonomics, but advices receive a
 * MessageContext so updates are explicit via with()/without()/merge().
 *
 * @implements \ArrayAccess<array-key, mixed>
 * @implements \IteratorAggregate<array-key, mixed>
 */
final readonly class MessageContext implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /**
     * @param array<array-key, mixed> $values
     */
    private function __construct(
        private array $values,
    ) {}

    /**
     * @param array<array-key, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function has(string|int $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string|int $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values)
            ? $this->values[$key]
            : $default;
    }

    public function with(string|int $key, mixed $value): self
    {
        $values = $this->values;
        $values[$key] = $value;

        return new self($values);
    }

    public function without(string|int $key): self
    {
        if (! array_key_exists($key, $this->values)) {
            return $this;
        }

        $values = $this->values;
        unset($values[$key]);

        return new self($values);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function merge(array $values): self
    {
        if ([] === $values) {
            return $this;
        }

        return new self([...$this->values, ...$values]);
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->values);
    }

    public function offsetExists(mixed $offset): bool
    {
        return (is_string($offset) || is_int($offset)) && array_key_exists($offset, $this->values);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (! is_string($offset) && ! is_int($offset)) {
            return null;
        }

        return array_key_exists($offset, $this->values)
            ? $this->values[$offset]
            : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('MessageContext is immutable; use with() or merge() to derive a new context.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('MessageContext is immutable; use without() to derive a new context.');
    }
}
