<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

/**
 * Immutable message metadata passed through the advice chain.
 *
 * The public bus APIs accept either arrays or MessageContext instances for
 * ergonomics, but advices always receive a MessageContext so updates are
 * explicit via with()/without()/merge().
 *
 * @implements \ArrayAccess<array-key, mixed>
 * @implements \IteratorAggregate<array-key, mixed>
 */
final readonly class MessageContext implements \ArrayAccess, \Countable, \IteratorAggregate
{
    public const LOCAL_PREFIX = '.';

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
        self::assertValuesAreValid($values);

        return new self($values);
    }

    /**
     * @param array<array-key, mixed>|self $values
     */
    public static function coerce(array|self $values): self
    {
        return $values instanceof self ? $values : self::fromArray($values);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(int|string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function with(int|string $key, mixed $value): self
    {
        self::assertValueIsValid($key, $value);

        $values = $this->values;
        $values[$key] = $value;

        return new self($values);
    }

    public function withLocal(string $key, mixed $value): self
    {
        $values = $this->values;
        $values[self::localKey($key)] = $value;

        return new self($values);
    }

    public function local(string $key, mixed $default = null): mixed
    {
        return $this->get(self::localKey($key), $default);
    }

    public function without(int|string $key): self
    {
        if (!array_key_exists($key, $this->values)) {
            return $this;
        }

        $values = $this->values;
        unset($values[$key]);

        return new self($values);
    }

    /**
     * @param array<array-key, mixed>|self $values
     */
    public function merge(array|self $values): self
    {
        $values = $values instanceof self ? $values->all() : $values;

        if ([] === $values) {
            return $this;
        }

        self::assertValuesAreValid($values);

        return new self([...$this->values, ...$values]);
    }

    public function propagated(): self
    {
        $values = [];

        foreach ($this->values as $key => $value) {
            if (self::isLocalKey($key)) {
                continue;
            }

            $values[$key] = $value;
        }

        return new self($values);
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->values);
    }

    /** @param mixed $offset ArrayAccess also receives invalid offsets that must be rejected. */
    public function offsetExists(mixed $offset): bool
    {
        return (is_string($offset) || is_int($offset)) && array_key_exists($offset, $this->values);
    }

    /** @param mixed $offset ArrayAccess also receives invalid offsets that must be rejected. */
    public function offsetGet(mixed $offset): mixed
    {
        if (!is_string($offset) && !is_int($offset)) {
            return null;
        }

        return array_key_exists($offset, $this->values) ? $this->values[$offset] : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException(
            'MessageContext is immutable; use with() or merge() to derive a new context.',
        );
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('MessageContext is immutable; use without() to derive a new context.');
    }

    public static function isLocalKey(mixed $key): bool
    {
        return is_string($key) && str_starts_with($key, self::LOCAL_PREFIX);
    }

    private static function localKey(string $key): string
    {
        if ('' === $key) {
            throw new \InvalidArgumentException('Local message context keys must be non-empty strings.');
        }

        return str_starts_with($key, self::LOCAL_PREFIX) ? $key : self::LOCAL_PREFIX . $key;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function assertValuesAreValid(array $values): void
    {
        foreach ($values as $key => $value) {
            self::assertValueIsValid($key, $value);
        }
    }

    private static function assertValueIsValid(int|string $key, mixed $value): void
    {
        if (self::isLocalKey($key)) {
            return;
        }

        if (!self::isPropagatedValue($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Message context value for key "%s" must be scalar, null, or a list of scalar/null values. Use a local key prefixed with "%s" for in-process values.',
                (string) $key,
                self::LOCAL_PREFIX,
            ));
        }
    }

    private static function isPropagatedValue(mixed $value): bool
    {
        if (null === $value || is_scalar($value)) {
            return true;
        }

        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (null !== $item && !is_scalar($item)) {
                return false;
            }
        }

        return true;
    }
}
