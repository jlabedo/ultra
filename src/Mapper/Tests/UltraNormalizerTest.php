<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
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

/**
 * @internal
 */
#[CoversNothing]
final class UltraNormalizerTest extends TestCase
{
    #[Test]
    public function testUltraNormalizerRoundTrip(): void
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
        // For now, just check that the email is an object or string
        self::assertTrue(is_string($array['email']) || $array['email'] instanceof Email);

        $restored = $normalizer->fromScalar($array, Customer::class);
        self::assertInstanceOf(Customer::class, $restored);
        self::assertEquals($customer->name, $restored->name);
        self::assertEquals($customer->phone, $restored->phone);
        // Email is an object, so compare value
        self::assertEquals((string) $customer->email, (string) $restored->email);
    }

    #[Test]
    public function testScalarOnlyObject(): void
    {
        $foo = new Foo(42, 'bar');
        $normalizer = new UltraNormalizer();
        $array = $normalizer->toScalar($foo);
        self::assertSame(['id' => 42, 'name' => 'bar'], $array);
        $restored = $normalizer->fromScalar($array, Foo::class);
        self::assertEquals($foo, $restored);
    }

    #[Test]
    public function testNestedValueObject(): void
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

    #[Test]
    public function testNestedNonValueObject(): void
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

    #[Test]
    public function testArrayOfValueObjects(): void
    {
        $emails = [new Email('a@b.com'), new Email('c@d.com')];
        $list = new EmailList($emails);
        $normalizer = new UltraNormalizer();
        $array = $normalizer->toScalar($list);
        self::assertSame(['a@b.com', 'c@d.com'], $array);
    }

    #[Test]
    public function testNullHandling(): void
    {
        $obj = new MaybeEmail(null);
        $normalizer = new UltraNormalizer();
        $array = $normalizer->toScalar($obj);
        self::assertSame(['email' => null], $array);
        $restored = $normalizer->fromScalar($array, MaybeEmail::class);
        self::assertEquals($obj, $restored);
    }

    #[Test]
    public function testDenormalizeArrayOfValueObjects(): void
    {
        $data = ['a@b.com', 'c@d.com'];
        $normalizer = new UltraNormalizer();
        $result = $normalizer->fromScalar($data, EmailList::class);
        self::assertInstanceOf(EmailList::class, $result);
        self::assertCount(2, $result->emails);
        self::assertContainsOnlyInstancesOf(Email::class, $result->emails);
        self::assertSame('a@b.com', $result->emails[0]->value);
        self::assertSame('c@d.com', $result->emails[1]->value);
    }

    #[Test]
    public function testUltraNormalizerHandlesDateTimeImmutable(): void
    {
        $normalizer = new UltraNormalizer();
        $address = new Address('123 Main St', 'Metropolis', '12345');
        $date = new \DateTimeImmutable('2024-06-01T12:34:56+00:00');
        $complex = new Complex(
            name: 'Alice',
            age: 30,
            score: 99.5,
            active: true,
            address: $address,
            tags: [1, 2, 3, 4, 5],
            createdAt: $date,
        );

        $normalized = $normalizer->toScalar($complex);
        $this->assertSame('2024-06-01T12:34:56+00:00', $normalized['createdAt']);

        $denormalized = $normalizer->fromScalar($normalized, Complex::class);
        $this->assertInstanceOf(\DateTimeImmutable::class, $denormalized->createdAt);
        $this->assertSame($date->format(DATE_ATOM), $denormalized->createdAt->format(DATE_ATOM));
    }

    #[Test]
    public function testDeeplyNestedStructure(): void
    {
        $user = new User('deep', new Address('42 Galaxy Way', 'Cosmos', '424242'));
        $emails = new EmailList([new Email('deep@space.com'), new Email('void@space.com')]);
        $deep = new DeepNest($user, $emails);

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

    #[Test]
    public function testArrayOfArrays(): void
    {
        $matrix = [
            [1, 2, 3],
            [4, 5, 6],
            [7, 8, 9],
        ];
        $obj = new ArrayOfArrays($matrix);
        $normalizer = new UltraNormalizer();
        $array = $normalizer->toScalar($obj);
        self::assertSame(['matrix' => $matrix], $array);
        $restored = $normalizer->fromScalar($array, ArrayOfArrays::class);
        self::assertEquals($obj, $restored);
    }

    #[Test]
    public function testArrayOfArrayOfObject(): void
    {
        $matrix = [
            [new Address('A1', 'C1', 'Z1'), new Address('A2', 'C2', 'Z2')],
            [new Address('B1', 'D1', 'Y1'), new Address('B2', 'D2', 'Y2')],
        ];
        $obj = new ArrayOfArrayOfObject($matrix);
        $normalizer = new UltraNormalizer();
        $array = $normalizer->toScalar($obj);
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
        self::assertEquals($obj, $restored);
    }

    #[Test]
    public function testNestedValueObjectAsValue(): void
    {
        $obj = new OuterValue(new InnerValue(42));
        $normalizer = new UltraNormalizer();
        $array = $normalizer->toScalar($obj);
        // If the generator is naive, it will just output the InnerValue object, not its scalar value.
        self::assertSame(42, $array);

        $restored = $normalizer->fromScalar($array, OuterValue::class);
        self::assertEquals($obj, $restored);
    }

    #[Test]
    public function testBackedEnumRoundTrip(): void
    {
        $normalizer = new UltraNormalizer();
        $obj = new EnumHolder(Status::ACTIVE);
        $array = $normalizer->toScalar($obj);
        self::assertSame(['status' => 'active', 'nullableStatus' => null], $array);
        $restored = $normalizer->fromScalar($array, EnumHolder::class);
        self::assertInstanceOf(EnumHolder::class, $restored);
        self::assertSame(Status::ACTIVE, $restored->status);
    }

    #[Test]
    public function testCircularReferenceThrows(): void
    {
        $a = new CircularA();
        $b = new CircularB($a);
        $a->b = $b;
        $normalizer = new UltraNormalizer();
        $this->expectException(CircularReferenceException::class);
        $normalizer->toScalar($a);
    }

    #[Test]
    public function testNullableUnionRoundTrip(): void
    {
        $normalizer = new UltraNormalizer();
        $obj = new MaybeEmail(new Email('foo@bar.com'));
        $array = $normalizer->toScalar($obj);
        self::assertSame(['email' => 'foo@bar.com'], $array);
        $restored = $normalizer->fromScalar($array, MaybeEmail::class);
        self::assertInstanceOf(MaybeEmail::class, $restored);
        self::assertInstanceOf(Email::class, $restored->email);
        self::assertSame('foo@bar.com', $restored->email->value);

        $objNull = new MaybeEmail(null);
        $arrayNull = $normalizer->toScalar($objNull);
        self::assertSame(['email' => null], $arrayNull);
        $restoredNull = $normalizer->fromScalar($arrayNull, MaybeEmail::class);
        self::assertInstanceOf(MaybeEmail::class, $restoredNull);
        self::assertNull($restoredNull->email);
    }

    #[Test]
    public function testMultiTypeUnionRoundTrip(): void
    {
        $normalizer = new UltraNormalizer();
        $objEmail = new MultiUnion(new Email('bar@baz.com'));
        $arrayEmail = $normalizer->toScalar($objEmail);
        self::assertSame(['value' => 'bar@baz.com'], $arrayEmail);
        $restoredEmail = $normalizer->fromScalar($arrayEmail, MultiUnion::class);
        self::assertInstanceOf(MultiUnion::class, $restoredEmail);
        self::assertInstanceOf(Email::class, $restoredEmail->value);
        self::assertSame('bar@baz.com', $restoredEmail->value->value);

        $objInt = new MultiUnion(42);
        $arrayInt = $normalizer->toScalar($objInt);
        self::assertSame(['value' => 42], $arrayInt);
        $restoredInt = $normalizer->fromScalar($arrayInt, MultiUnion::class);
        self::assertInstanceOf(MultiUnion::class, $restoredInt);
        self::assertSame(42, $restoredInt->value);

        $objNull = new MultiUnion(null);
        $arrayNull = $normalizer->toScalar($objNull);
        self::assertSame(['value' => null], $arrayNull);
        $restoredNull = $normalizer->fromScalar($arrayNull, MultiUnion::class);
        self::assertInstanceOf(MultiUnion::class, $restoredNull);
        self::assertNull($restoredNull->value);
    }

    #[Test]
    public function testUnionInvalidDataThrows(): void
    {
        $normalizer = new UltraNormalizer();
        $this->expectException(DenormalizationException::class);
        $normalizer->fromScalar(['value' => ['not' => 'valid']], MultiUnion::class);
    }

    #[Test]
    public function testPromotedAndNonPromotedProperties(): void
    {
        // Class with both promoted and non-promoted public properties
        // e.g. public string $promoted, public string $nonPromoted
        require_once __DIR__.'/Fixtures/PromotedAndNonPromoted.php';
        $obj = new PromotedAndNonPromoted('foo');
        $obj->nonPromoted = 'bar';
        $normalizer = new UltraNormalizer();
        $array = $normalizer->toScalar($obj);
        self::assertEquals([
            'promoted' => 'foo',
            'nonPromoted' => 'bar',
        ], $array);
        $restored = $normalizer->fromScalar($array, PromotedAndNonPromoted::class);
        self::assertInstanceOf(PromotedAndNonPromoted::class, $restored);
        self::assertSame('foo', $restored->promoted);
        self::assertSame('bar', $restored->nonPromoted);
    }

    #[Test]
    public function testTypeMismatchThrowsWithPath(): void
    {
        $normalizer = new UltraNormalizer();
        $data = ['id' => 'not-an-int', 'name' => 'bar']; // id should be int

        try {
            $normalizer->fromScalar($data, Foo::class);
            self::fail('Expected DenormalizationException');
        } catch (DenormalizationException $e) {
            self::assertStringContainsString('id', $e->getMessage());
            self::assertStringContainsString('expected int', $e->getMessage());
            self::assertStringContainsString('[id]', $e->getMessage());
        }
    }

    public function testArrayMap(): void
    {
        $result = array_map(fn ($v) => $context = new \stdClass() ? $v : null, [1, 2, 3]);
        self::assertSame([1, 2, 3], $result);
    }

    #[Test]
    public function testCollectionDenormalizationTypeError(): void
    {
        $normalizer = new UltraNormalizer();
        $data = ['a@b.com', 123]; // 123 is not a valid Email

        try {
            $normalizer->fromScalar($data, EmailList::class);
            self::fail('Expected DenormalizationException');
        } catch (DenormalizationException $e) {
            self::assertStringContainsString('expected', $e->getMessage());
            self::assertStringContainsString('[1]', $e->getMessage());
        }
    }

    #[Test]
    public function testNestedObjectPropertyTypeMismatch(): void
    {
        $normalizer = new UltraNormalizer();
        $data = [
            'username' => 'bob',
            'address' => [
                'street' => '1 Main',
                'city' => 'Paris',
                'zip' => 12345, // should be string
            ],
        ];

        try {
            $normalizer->fromScalar($data, User::class);
            self::fail('Expected DenormalizationException');
        } catch (DenormalizationException $e) {
            self::assertStringContainsString('zip', $e->getMessage());
            self::assertStringContainsString('expected string', $e->getMessage());
            self::assertStringContainsString('[address][zip]', $e->getMessage());
        }
    }

    #[Test]
    public function testDeeplyNestedStructureTypeMismatch(): void
    {
        $normalizer = new UltraNormalizer();
        $data = [
            'user' => [
                'username' => 'deep',
                'address' => [
                    'street' => '42 Galaxy Way',
                    'city' => 'Cosmos',
                    'zip' => 424242, // should be string
                ],
            ],
            'emails' => ['deep@space.com', 'void@space.com'],
        ];

        try {
            $normalizer->fromScalar($data, DeepNest::class);
            self::fail('Expected DenormalizationException');
        } catch (DenormalizationException $e) {
            self::assertStringContainsString('zip', $e->getMessage());
            self::assertStringContainsString('expected string', $e->getMessage());
            self::assertStringContainsString('[user][address][zip]', $e->getMessage());
        }
    }

    #[Test]
    public function testMissingRequiredPropertyThrowsWithPath(): void
    {
        $normalizer = new UltraNormalizer();
        $data = ['name' => 'bar']; // missing id

        try {
            $normalizer->fromScalar($data, Foo::class);
            self::fail('Expected DenormalizationException');
        } catch (DenormalizationException $e) {
            self::assertStringContainsString('id', $e->getMessage());
            self::assertStringContainsString('Missing required property', $e->getMessage());
            self::assertStringContainsString('[id]', $e->getMessage());
        }
    }

    #[Test]
    public function testUnionTypeMismatchThrowsWithPath(): void
    {
        $normalizer = new UltraNormalizer();
        $data = ['value' => ['not' => 'valid']]; // MultiUnion expects Email|int|null

        try {
            $normalizer->fromScalar($data, MultiUnion::class);
            self::fail('Expected DenormalizationException');
        } catch (DenormalizationException $e) {
            self::assertStringContainsString('value', $e->getMessage());
            self::assertStringContainsString('does not match any type', $e->getMessage());
            self::assertStringContainsString('[value]', $e->getMessage());
        }
    }

    #[Test]
    public function testRuntimeNormalizerPriority(): void
    {
        $normalizer = new UltraNormalizer();
        $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());
        $runtimeEmail = new RuntimeEmail('foo@bar.com');
        $array = $normalizer->toScalar($runtimeEmail);
        self::assertSame('runtime-email:foo@bar.com', $array, 'Runtime normalizer toScalar should be used');
        $restored = $normalizer->fromScalar('runtime-email:foo@bar.com', $runtimeEmail::class);
        self::assertEquals($runtimeEmail, $restored);
    }

    #[Test]
    public function testRuntimeNormalizerCalledForNestedProperty(): void
    {
        $normalizer = new UltraNormalizer();
        $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());
        $customer = new CustomerWithRuntimeEmail('Jane', new RuntimeEmail('jane@doe.com'), '+987654321');
        $array = $normalizer->toScalar($customer);
        self::assertSame([
            'name' => 'Jane',
            'email' => 'runtime-email:jane@doe.com',
            'phone' => '+987654321',
        ], $array, 'Runtime normalizer should be used for nested RuntimeEmail property');
        $restored = $normalizer->fromScalar($array, CustomerWithRuntimeEmail::class);
        self::assertEquals($customer, $restored);
    }
}

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
