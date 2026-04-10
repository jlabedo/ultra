<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;
use Ultra\Mapper\Exception\CircularReferenceException;
use Ultra\Mapper\Exception\DenormalizationException;
use Ultra\Mapper\Tests\Fixtures\Address;
use Ultra\Mapper\Tests\Fixtures\ArrayOfArrayOfObject;
use Ultra\Mapper\Tests\Fixtures\ArrayOfArrays;
use Ultra\Mapper\Tests\Fixtures\Complex;
use Ultra\Mapper\Tests\Fixtures\Customer;
use Ultra\Mapper\Tests\Fixtures\DeepNest;
use Ultra\Mapper\Tests\Fixtures\Email;
use Ultra\Mapper\Tests\Fixtures\EmailList;
use Ultra\Mapper\Tests\Fixtures\EnumHolder;
use Ultra\Mapper\Tests\Fixtures\Foo;
use Ultra\Mapper\Tests\Fixtures\InnerValue;
use Ultra\Mapper\Tests\Fixtures\MaybeEmail;
use Ultra\Mapper\Tests\Fixtures\OuterValue;
use Ultra\Mapper\Tests\Fixtures\PromotedAndNonPromoted;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmailNormalizer;
use Ultra\Mapper\Tests\Fixtures\Status;
use Ultra\Mapper\Tests\Fixtures\User;
use Ultra\Mapper\UltraNormalizer;

class CircularA
{
    public function __construct(public ?CircularB $b = null) {}
}

class CircularB
{
    public function __construct(public ?CircularA $a = null) {}
}

class MultiUnion
{
    public function __construct(
        public Email|int|null $value,
    ) {}
}

readonly class CustomerWithRuntimeEmail
{
    public function __construct(
        public string $name,
        public RuntimeEmail $email,
        public string $phone,
    ) {}
}

/**
 * @internal
 */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
final class UltraNormalizerTest extends TestCase
{
    public function test_ultra_normalizer_round_trip(): void
    {
        $normalizer = new UltraNormalizer();
        $customer = new Customer(
            name: 'John Doe',
            email: new Email('jdoe@octorenov.com'),
            phone: '+1234567890',
        );

        $array = $normalizer->toScalar($customer);

        self::assertSame([
            'name' => 'John Doe',
            'email' => 'jdoe@octorenov.com',
            'phone' => '+1234567890',
        ], $array);
        self::assertTrue(\is_string($array['email']) || $array['email'] instanceof Email);

        $restored = $normalizer->fromScalar($array, Customer::class);

        self::assertEquals($customer, $restored);
    }

    public function test_scalar_only_object(): void
    {
        $foo = new Foo(42, 'bar');
        $normalizer = new UltraNormalizer();

        $array = $normalizer->toScalar($foo);

        self::assertSame(['id' => 42, 'name' => 'bar'], $array);

        $restored = $normalizer->fromScalar($array, Foo::class);

        self::assertEquals($foo, $restored);
    }

    public function test_nested_value_object(): void
    {
        $customer = new Customer(
            name: 'Alice',
            email: new Email('alice@example.com'),
            phone: '123',
        );
        $normalizer = new UltraNormalizer();

        $array = $normalizer->toScalar($customer);

        self::assertSame([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'phone' => '123',
        ], $array);

        $restored = $normalizer->fromScalar($array, Customer::class);

        self::assertEquals($customer, $restored);
    }

    public function test_nested_non_value_object(): void
    {
        $user = new User('bob', new Address('1 Main', 'Paris', '75000'));
        $normalizer = new UltraNormalizer();

        $array = $normalizer->toScalar($user);

        self::assertSame([
            'username' => 'bob',
            'address' => [
                'street' => '1 Main',
                'city' => 'Paris',
                'zip' => '75000',
            ],
        ], $array);

        $restored = $normalizer->fromScalar($array, User::class);

        self::assertEquals($user, $restored);
    }

    public function test_array_of_value_objects(): void
    {
        $emails = [new Email('a@b.com'), new Email('c@d.com')];
        $list = new EmailList($emails);
        $normalizer = new UltraNormalizer();

        self::assertSame(['a@b.com', 'c@d.com'], $normalizer->toScalar($list));
    }

    public function test_null_handling(): void
    {
        $object = new MaybeEmail(null);
        $normalizer = new UltraNormalizer();

        $array = $normalizer->toScalar($object);

        self::assertSame(['email' => null], $array);

        $restored = $normalizer->fromScalar($array, MaybeEmail::class);

        self::assertEquals($object, $restored);
    }

    public function test_denormalize_array_of_value_objects(): void
    {
        $normalizer = new UltraNormalizer();

        $result = $normalizer->fromScalar(['a@b.com', 'c@d.com'], EmailList::class);

        self::assertInstanceOf(EmailList::class, $result);
        self::assertCount(2, $result->emails);
        self::assertContainsOnlyInstancesOf(Email::class, $result->emails);
        self::assertSame('a@b.com', $result->emails[0]->value);
        self::assertSame('c@d.com', $result->emails[1]->value);
    }

    public function test_ultra_normalizer_handles_date_time_immutable(): void
    {
        $normalizer = new UltraNormalizer();
        $date = new \DateTimeImmutable('2024-06-01T12:34:56+00:00');
        $complex = new Complex(
            name: 'Alice',
            age: 30,
            score: 99.5,
            active: true,
            address: new Address('123 Main St', 'Metropolis', '12345'),
            tags: [1, 2, 3, 4, 5],
            createdAt: $date,
        );

        $normalized = $normalizer->toScalar($complex);

        self::assertSame('2024-06-01T12:34:56+00:00', $normalized['createdAt']);

        $denormalized = $normalizer->fromScalar($normalized, Complex::class);

        self::assertInstanceOf(\DateTimeImmutable::class, $denormalized->createdAt);
        self::assertSame($date->format(\DATE_ATOM), $denormalized->createdAt->format(\DATE_ATOM));
    }

    public function test_deeply_nested_structure(): void
    {
        $deep = new DeepNest(
            new User('deep', new Address('42 Galaxy Way', 'Cosmos', '424242')),
            new EmailList([new Email('deep@space.com'), new Email('void@space.com')]),
        );
        $normalizer = new UltraNormalizer();

        $array = $normalizer->toScalar($deep);

        self::assertSame([
            'user' => [
                'username' => 'deep',
                'address' => [
                    'street' => '42 Galaxy Way',
                    'city' => 'Cosmos',
                    'zip' => '424242',
                ],
            ],
            'emails' => ['deep@space.com', 'void@space.com'],
        ], $array);

        $restored = $normalizer->fromScalar($array, DeepNest::class);

        self::assertEquals($deep, $restored);
    }

    public function test_array_of_arrays(): void
    {
        $matrix = [
            [1, 2, 3],
            [4, 5, 6],
            [7, 8, 9],
        ];
        $object = new ArrayOfArrays($matrix);
        $normalizer = new UltraNormalizer();

        $array = $normalizer->toScalar($object);

        self::assertSame(['matrix' => $matrix], $array);

        $restored = $normalizer->fromScalar($array, ArrayOfArrays::class);

        self::assertEquals($object, $restored);
    }

    public function test_array_of_array_of_object(): void
    {
        $matrix = [
            [new Address('A1', 'C1', 'Z1'), new Address('A2', 'C2', 'Z2')],
            [new Address('B1', 'D1', 'Y1'), new Address('B2', 'D2', 'Y2')],
        ];
        $object = new ArrayOfArrayOfObject($matrix);
        $normalizer = new UltraNormalizer();

        $array = $normalizer->toScalar($object);

        self::assertSame([
            'matrix' => [
                [
                    ['street' => 'A1', 'city' => 'C1', 'zip' => 'Z1'],
                    ['street' => 'A2', 'city' => 'C2', 'zip' => 'Z2'],
                ],
                [
                    ['street' => 'B1', 'city' => 'D1', 'zip' => 'Y1'],
                    ['street' => 'B2', 'city' => 'D2', 'zip' => 'Y2'],
                ],
            ],
        ], $array);

        $restored = $normalizer->fromScalar($array, ArrayOfArrayOfObject::class);

        self::assertEquals($object, $restored);
    }

    public function test_nested_value_object_as_value(): void
    {
        $object = new OuterValue(new InnerValue(42));
        $normalizer = new UltraNormalizer();

        $array = $normalizer->toScalar($object);

        self::assertSame(42, $array);

        $restored = $normalizer->fromScalar($array, OuterValue::class);

        self::assertEquals($object, $restored);
    }

    public function test_backed_enum_round_trip(): void
    {
        $normalizer = new UltraNormalizer();
        $object = new EnumHolder(Status::ACTIVE);

        $array = $normalizer->toScalar($object);

        self::assertSame(['status' => 'active', 'nullableStatus' => null], $array);

        $restored = $normalizer->fromScalar($array, EnumHolder::class);

        self::assertEquals(new EnumHolder(Status::ACTIVE), $restored);
    }

    public function test_circular_reference_throws(): void
    {
        $a = new CircularA();
        $b = new CircularB($a);
        $a->b = $b;
        $normalizer = new UltraNormalizer();

        $this->expectException(CircularReferenceException::class);

        $normalizer->toScalar($a);
    }

    public function test_nullable_union_round_trip(): void
    {
        $normalizer = new UltraNormalizer();

        $object = new MaybeEmail(new Email('foo@bar.com'));
        $array = $normalizer->toScalar($object);
        self::assertSame(['email' => 'foo@bar.com'], $array);
        self::assertEquals(new MaybeEmail(new Email('foo@bar.com')), $normalizer->fromScalar($array, MaybeEmail::class));

        $nullObject = new MaybeEmail(null);
        $nullArray = $normalizer->toScalar($nullObject);
        self::assertSame(['email' => null], $nullArray);
        self::assertEquals(new MaybeEmail(null), $normalizer->fromScalar($nullArray, MaybeEmail::class));
    }

    public function test_multi_type_union_round_trip(): void
    {
        $normalizer = new UltraNormalizer();

        $emailObject = new MultiUnion(new Email('bar@baz.com'));
        $emailArray = $normalizer->toScalar($emailObject);
        self::assertSame(['value' => 'bar@baz.com'], $emailArray);
        self::assertEquals(new MultiUnion(new Email('bar@baz.com')), $normalizer->fromScalar($emailArray, MultiUnion::class));

        $intObject = new MultiUnion(42);
        $intArray = $normalizer->toScalar($intObject);
        self::assertSame(['value' => 42], $intArray);
        self::assertEquals(new MultiUnion(42), $normalizer->fromScalar($intArray, MultiUnion::class));

        $nullObject = new MultiUnion(null);
        $nullArray = $normalizer->toScalar($nullObject);
        self::assertSame(['value' => null], $nullArray);
        self::assertEquals(new MultiUnion(null), $normalizer->fromScalar($nullArray, MultiUnion::class));
    }

    public function test_union_invalid_data_throws(): void
    {
        $normalizer = new UltraNormalizer();

        $this->expectException(DenormalizationException::class);

        $normalizer->fromScalar(['value' => ['not' => 'valid']], MultiUnion::class);
    }

    public function test_promoted_and_non_promoted_properties(): void
    {
        $object = new PromotedAndNonPromoted('foo');
        $object->nonPromoted = 'bar';
        $normalizer = new UltraNormalizer();

        $array = $normalizer->toScalar($object);

        self::assertEquals([
            'promoted' => 'foo',
            'nonPromoted' => 'bar',
        ], $array);

        $restored = $normalizer->fromScalar($array, PromotedAndNonPromoted::class);

        self::assertEquals($object, $restored);
    }

    public function test_type_mismatch_throws_with_path(): void
    {
        $normalizer = new UltraNormalizer();

        $this->assertDenormalizationMessageContains(
            static fn(): object => $normalizer->fromScalar(['id' => 'not-an-int', 'name' => 'bar'], Foo::class),
            'id',
            'expected int',
            '[id]',
        );
    }

    public function test_array_map(): void
    {
        $result = array_map(fn($value) => $context = new \stdClass() ? $value : null, [1, 2, 3]);

        self::assertSame([1, 2, 3], $result);
    }

    public function test_collection_denormalization_type_error(): void
    {
        $normalizer = new UltraNormalizer();

        $this->assertDenormalizationMessageContains(
            static fn(): object => $normalizer->fromScalar(['a@b.com', 123], EmailList::class),
            'expected',
            '[1]',
        );
    }

    public function test_nested_object_property_type_mismatch(): void
    {
        $normalizer = new UltraNormalizer();

        $this->assertDenormalizationMessageContains(
            static fn(): object => $normalizer->fromScalar([
                'username' => 'bob',
                'address' => [
                    'street' => '1 Main',
                    'city' => 'Paris',
                    'zip' => 12345,
                ],
            ], User::class),
            'zip',
            'expected string',
            '[address][zip]',
        );
    }

    public function test_deeply_nested_structure_type_mismatch(): void
    {
        $normalizer = new UltraNormalizer();

        $this->assertDenormalizationMessageContains(
            static fn(): object => $normalizer->fromScalar([
                'user' => [
                    'username' => 'deep',
                    'address' => [
                        'street' => '42 Galaxy Way',
                        'city' => 'Cosmos',
                        'zip' => 424242,
                    ],
                ],
                'emails' => ['deep@space.com', 'void@space.com'],
            ], DeepNest::class),
            'zip',
            'expected string',
            '[user][address][zip]',
        );
    }

    public function test_missing_required_property_throws_with_path(): void
    {
        $normalizer = new UltraNormalizer();

        $this->assertDenormalizationMessageContains(
            static fn(): object => $normalizer->fromScalar(['name' => 'bar'], Foo::class),
            'id',
            'Missing required property',
            '[id]',
        );
    }

    public function test_union_type_mismatch_throws_with_path(): void
    {
        $normalizer = new UltraNormalizer();

        $this->assertDenormalizationMessageContains(
            static fn(): object => $normalizer->fromScalar(['value' => ['not' => 'valid']], MultiUnion::class),
            'value',
            'does not match any type',
            '[value]',
        );
    }

    public function test_runtime_normalizer_priority(): void
    {
        $normalizer = new UltraNormalizer();
        $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());
        $runtimeEmail = new RuntimeEmail('foo@bar.com');

        $array = $normalizer->toScalar($runtimeEmail);

        self::assertSame('runtime-email:foo@bar.com', $array);

        $restored = $normalizer->fromScalar('runtime-email:foo@bar.com', $runtimeEmail::class);

        self::assertEquals($runtimeEmail, $restored);
    }

    public function test_runtime_normalizer_called_for_nested_property(): void
    {
        $normalizer = new UltraNormalizer();
        $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());
        $customer = new CustomerWithRuntimeEmail('Jane', new RuntimeEmail('jane@doe.com'), '+987654321');

        $array = $normalizer->toScalar($customer);

        self::assertSame([
            'name' => 'Jane',
            'email' => 'runtime-email:jane@doe.com',
            'phone' => '+987654321',
        ], $array);

        $restored = $normalizer->fromScalar($array, CustomerWithRuntimeEmail::class);

        self::assertEquals($customer, $restored);
    }

    private function assertDenormalizationMessageContains(callable $callback, string ...$needles): void
    {
        try {
            $callback();
            self::fail('Expected DenormalizationException to be thrown.');
        } catch (DenormalizationException $exception) {
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $exception->getMessage());
            }
        }
    }
}
