<?php

declare(strict_types=1);

namespace Ultra\Mapper_Example\Domain;

use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper\UltraNormalizer;

final class RuntimeEmailNormalizer implements RuntimeNormalizerInterface
{
    public static function handledTypes(): iterable
    {
        return [RuntimeEmail::class];
    }

    public function toScalar(mixed $value, UltraNormalizer $normalizer): array|bool|float|int|string|null
    {
        if (!$value instanceof RuntimeEmail) {
            throw new \InvalidArgumentException('Expected RuntimeEmail');
        }

        return $value->value();
    }

    public function fromScalar(
        array|bool|float|int|string|null $value,
        string $type,
        UltraNormalizer $normalizer,
        DenormalizationContext $context,
    ): mixed {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Expected string');
        }

        return new RuntimeEmail($value);
    }
}
