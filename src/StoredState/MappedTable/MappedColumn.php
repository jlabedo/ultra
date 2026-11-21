<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class MappedColumn
{
    public function __construct(
        public string $type,
        public bool $nullable = false,
    ) {
        if (!in_array($type, ['int', 'string', 'bool', 'float', 'array'], true)) {
            throw new \InvalidArgumentException('Unsupported mapped column type "' . $type . '".');
        }
    }

    public function accepts(mixed $value): bool
    {
        if (null === $value) {
            return $this->nullable;
        }

        return get_debug_type($value) === $this->type && (!is_array($value) || self::isNormalized($value));
    }

    private static function isNormalized(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (null !== \ReflectionReference::fromArrayElement($value, $key) || !self::isNormalized($item)) {
                    return false;
                }
            }

            return true;
        }

        return null === $value || is_scalar($value);
    }
}
