<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Codec;

use Ultra\MappedTable\ColumnCodec;

final class TextCodec extends ColumnCodec
{
    public function __construct(
        public readonly ?int $maxLength = null,
        public readonly bool $stringableKeys = true,
    ) {}

    public static function representation(): string
    {
        return 'string';
    }

    public static function supportsKeys(): bool
    {
        return true;
    }

    public function normalize(mixed $value): string
    {
        if (!is_string($value) || str_contains($value, "\0") || 1 !== preg_match('//u', $value)) {
            throw new \InvalidArgumentException('Mapped text must be a valid UTF-8 string without NUL.');
        }
        $limit = $this->maxLength;
        if (null !== $limit && strlen($value) > $limit) {
            $length = preg_match_all('/./us', $value);
            if (false === $length || $length > $limit) {
                throw new \InvalidArgumentException(sprintf('Expected at most %d characters.', $limit));
            }
        }

        return $value;
    }

    public function key(mixed $value): string
    {
        return $this->normalize($this->stringableKeys && $value instanceof \Stringable ? (string) $value : $value);
    }
}
