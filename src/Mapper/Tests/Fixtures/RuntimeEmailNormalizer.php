<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper\UltraNormalizer;

class RuntimeEmailNormalizer implements RuntimeNormalizerInterface
{
    public static function handledTypes(): iterable
    {
        return [RuntimeEmail::class];
    }

    public function toScalar(mixed $value, UltraNormalizer $normalizer): array|bool|float|int|string|null
    {
        return 'runtime-email:'.$value->value;
    }

    public function fromScalar(array|bool|float|int|string|null $value, string $type, UltraNormalizer $normalizer, DenormalizationContext $context): mixed
    {
        $val = is_array($value) ? ($value['value'] ?? '') : (string) $value;
        if (str_starts_with($val, 'runtime-email:')) {
            $val = substr($val, strlen('runtime-email:'));
        }

        return new RuntimeEmail($val);
    }
}
