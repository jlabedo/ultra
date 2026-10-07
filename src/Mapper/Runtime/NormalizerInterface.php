<?php

declare(strict_types=1);

namespace Ultra\Mapper;

/**
 * Interface for custom (de)normalizers for specific types.
 */
interface NormalizerInterface
{
    public function toScalar(mixed $value, UltraNormalizer $normalizer): array|bool|float|int|string|null;

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     * @return T
     */
    public function fromScalar(
        array|bool|float|int|string|null $value,
        string $type,
        UltraNormalizer $normalizer,
        DenormalizationContext $context,
    ): mixed;
}
