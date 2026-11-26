<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Codec;

use Ultra\MappedTable\ColumnCodec;
use Ultra\Sql\ColumnType;
use Ultra\Sql\SqlType;

/** @internal */
final class TextCodec extends ColumnCodec
{
    public static function representation(): string
    {
        return 'string';
    }

    public static function supportsKeys(): bool
    {
        return true;
    }

    public function normalize(SqlType $type, mixed $value): string
    {
        if (!is_string($value) || str_contains($value, "\0") || 1 !== preg_match('//u', $value)) {
            throw new \InvalidArgumentException('Mapped text must be a valid UTF-8 string without NUL.');
        }
        $limit = $type->length;
        if (null !== $limit && strlen($value) > $limit) {
            $length = preg_match_all('/./us', $value);
            if (false === $length || $length > $limit) {
                throw new \InvalidArgumentException(sprintf('Expected at most %d characters.', $limit));
            }
        }

        return $value;
    }

    public function key(SqlType $type, mixed $value): string
    {
        return $this->normalize(
            $type,
            ColumnType::Text === $type->family && $value instanceof \Stringable ? (string) $value : $value,
        );
    }
}
