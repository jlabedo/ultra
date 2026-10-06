<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

/**
 * Transport headers carried by an async message.
 *
 * @phpstan-type HeaderValue null|bool|int|float|string|list<null|bool|int|float|string>
 */
final readonly class AsyncHeaders
{
    /**
     * @param array<string, null|bool|int|float|string|list<null|bool|int|float|string>> $values
     */
    private function __construct(private array $values) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, null|bool|int|float|string|list<null|bool|int|float|string>> $values
     */
    public static function fromArray(array $values): self
    {
        $normalized = [];

        foreach ($values as $name => $value) {
            if (!is_string($name) || '' === $name) {
                throw new \InvalidArgumentException('Async header names must be non-empty strings.');
            }

            self::assertValidValue($name, $value);
            $normalized[self::normalizeName($name)] = $value;
        }

        return new self($normalized);
    }

    public function has(string $name): bool
    {
        return array_key_exists(self::normalizeName($name), $this->values);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $name = self::normalizeName($name);

        return array_key_exists($name, $this->values) ? $this->values[$name] : $default;
    }

    public function getString(string $name): ?string
    {
        $value = $this->get($name);

        return is_string($value) ? $value : null;
    }

    public function with(string $name, null|bool|int|float|string|array $value): self
    {
        self::assertValidValue($name, $value);

        return new self([
            ...$this->values,
            self::normalizeName($name) => $value,
        ]);
    }

    public function without(string $name): self
    {
        $name = self::normalizeName($name);

        if (!array_key_exists($name, $this->values)) {
            return $this;
        }

        $values = $this->values;
        unset($values[$name]);

        return new self($values);
    }

    /**
     * @return array<string, null|bool|int|float|string|list<null|bool|int|float|string>>
     */
    public function all(): array
    {
        return $this->values;
    }

    private static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        if ('' === $name) {
            throw new \InvalidArgumentException('Async header names must be non-empty strings.');
        }

        return $name;
    }

    private static function assertValidValue(string $name, mixed $value): void
    {
        if (null === $value || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return;
        }

        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $item) {
                if (null !== $item && !is_bool($item) && !is_int($item) && !is_float($item) && !is_string($item)) {
                    throw new \InvalidArgumentException(sprintf('Async header "%s" contains a non-scalar list value.', $name));
                }
            }

            return;
        }

        throw new \InvalidArgumentException(sprintf('Async header "%s" must be a scalar, null, or a list of scalar values.', $name));
    }
}
