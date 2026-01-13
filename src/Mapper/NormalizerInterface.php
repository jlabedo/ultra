<?php

namespace Ultra\Mapper;

/**
 * Interface for custom (de)normalizers for specific types.
 */
interface NormalizerInterface
{
    public function toScalar(mixed $value, UltraNormalizer $normalizer): array|bool|float|int|string|null;

    /**
     * @param class-string $type
     */
    public function fromScalar(array|bool|float|int|string|null $value, string $type, UltraNormalizer $normalizer, DenormalizationContext $context): mixed;
}
