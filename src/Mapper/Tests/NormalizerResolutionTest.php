<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use Ultra\Mapper\Tests\Fixtures\CustomerWithRuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\DerivedRuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\Foo;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmailNormalizer;
use Ultra\Mapper\Tests\Fixtures\RuntimeUuidNormalizer;
use Ultra\Mapper\Tests\Fixtures\UuidOverrideRecord;
use Ultra\Mapper\Tests\Support\MapperTestCase;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
final class NormalizerResolutionTest extends MapperTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_mapping_works_without_the_optional_symfony_uid_classes(): void
    {
        $autoloaders = spl_autoload_functions();
        foreach ($autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }
        $withoutUid = static function (string $class) use ($autoloaders): void {
            if (str_starts_with($class, 'Symfony\\Component\\Uid\\')) {
                return;
            }
            foreach ($autoloaders as $autoloader) {
                $autoloader($class);
                if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
                    return;
                }
            }
        };
        spl_autoload_register($withoutUid);

        try {
            static::assertFalse(class_exists(Uuid::class));
            $normalizer = $this->normalizer();
            static::assertSame(['id' => 42, 'name' => 'bar'], $normalizer->toScalar(new Foo(42, 'bar')));
            static::assertEquals(
                new Foo(42, 'bar'),
                $normalizer->fromScalar(['id' => 42, 'name' => 'bar'], Foo::class),
            );
        } finally {
            spl_autoload_unregister($withoutUid);
            foreach ($autoloaders as $autoloader) {
                spl_autoload_register($autoloader);
            }
        }
    }

    public function test_registered_uuid_normalizers_override_the_builtin_representation(): void
    {
        $normalizer = $this->normalizer();
        $normalizer->registerRuntimeNormalizer(new RuntimeUuidNormalizer());
        $uuid = new UuidV4('12345678-1234-4234-8234-123456789abc');
        $representation = 'uuid:12345678-1234-4234-8234-123456789abc';

        static::assertSame($representation, $normalizer->toScalar($uuid));
        static::assertEquals($uuid, $normalizer->fromScalar($representation, UuidV4::class));
        // The declared Uuid type keeps its built-in representation; the exact UuidV4 type uses the override.
        $record = new UuidOverrideRecord($uuid, $uuid);
        $data = ['id' => '12345678-1234-4234-8234-123456789abc', 'custom' => $representation];
        static::assertSame($data, $normalizer->toScalar($record));
        static::assertEquals($record, $normalizer->fromScalar($data, UuidOverrideRecord::class));
    }

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
