<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\Mapper\Tests\Fixtures\CustomerWithRuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\DerivedRuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmailNormalizer;
use Ultra\Mapper\Tests\Support\MapperTestCase;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
final class NormalizerResolutionTest extends MapperTestCase
{
    public function test_registered_types_use_the_runtime_normalizer_at_root_and_in_generated_properties(): void
    {
        $normalizer = $this->normalizer();
        $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());
        $email = new RuntimeEmail('ada@example.test');
        $customer = new CustomerWithRuntimeEmail('Ada', $email, '123');

        static::assertSame('runtime-email:ada@example.test', $normalizer->toScalar($email));
        static::assertEquals($email, $normalizer->fromScalar('runtime-email:ada@example.test', RuntimeEmail::class));
        static::assertSame(
            [
                'name' => 'Ada',
                'email' => 'runtime-email:ada@example.test',
                'phone' => '123',
            ],
            $normalizer->toScalar($customer),
        );
        static::assertEquals($customer, $normalizer->fromScalar([
            'name' => 'Ada',
            'email' => 'runtime-email:ada@example.test',
            'phone' => '123',
        ], CustomerWithRuntimeEmail::class));

        $derived = new DerivedRuntimeEmail('derived@example.test');
        static::assertSame(['value' => 'derived@example.test'], $normalizer->toScalar($derived));
        static::assertEquals($derived, $normalizer->fromScalar([
            'value' => 'derived@example.test',
        ], DerivedRuntimeEmail::class));
    }

    public function test_cached_generated_normalizers_take_precedence_over_late_registration(): void
    {
        $normalizer = $this->normalizer();
        $email = new RuntimeEmail('ada@example.test');
        static::assertSame(['value' => 'ada@example.test'], $normalizer->toScalar($email));

        $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());

        static::assertSame(['value' => 'ada@example.test'], $normalizer->toScalar($email));
        static::assertEquals($email, $normalizer->fromScalar(['value' => 'ada@example.test'], RuntimeEmail::class));
    }
}
