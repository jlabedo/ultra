<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

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

test('UltraNormalizer round trip', function () {
    $normalizer = new UltraNormalizer();
    $customer = new Customer(
        name: 'John Doe',
        email: new Email('jdoe@octorenov.com'),
        phone: '+1234567890',
    );

    $array = $normalizer->toScalar($customer);
    expect($array)->toBe([
        'name' => 'John Doe',
        'email' => 'jdoe@octorenov.com',
        'phone' => '+1234567890',
    ])
        ->and(is_string($array['email']) || $array['email'] instanceof Email)->toBeTrue()
    ;
    // For now, just check that the email is an object or string

    $restored = $normalizer->fromScalar($array, Customer::class);
    expect($restored)->toEqual($customer);
});

test('scalar only object', function () {
    $foo = new Foo(42, 'bar');
    $normalizer = new UltraNormalizer();
    $array = $normalizer->toScalar($foo);
    expect($array)->toBe(['id' => 42, 'name' => 'bar']);
    $restored = $normalizer->fromScalar($array, Foo::class);
    expect($restored)->toEqual($foo);
});

test('nested value object', function () {
    $customer = new Customer(
        name: 'Alice',
        email: new Email('alice@example.com'),
        phone: '123',
    );
    $normalizer = new UltraNormalizer();
    $array = $normalizer->toScalar($customer);
    expect($array)->toBe([
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'phone' => '123',
    ]);
    $restored = $normalizer->fromScalar($array, Customer::class);
    expect($restored)->toEqual($customer);
});

test('nested non value object', function () {
    $user = new User('bob', new Address('1 Main', 'Paris', '75000'));
    $normalizer = new UltraNormalizer();
    $array = $normalizer->toScalar($user);
    expect($array)->toBe([
        'username' => 'bob',
        'address' => [
            'street' => '1 Main',
            'city' => 'Paris',
            'zip' => '75000',
        ],
    ]);
    $restored = $normalizer->fromScalar($array, User::class);
    expect($restored)->toEqual($user);
});

test('array of value objects', function () {
    $emails = [new Email('a@b.com'), new Email('c@d.com')];
    $list = new EmailList($emails);
    $normalizer = new UltraNormalizer();
    $array = $normalizer->toScalar($list);
    expect($array)->toBe(['a@b.com', 'c@d.com']);
});

test('null handling', function () {
    $obj = new MaybeEmail(null);
    $normalizer = new UltraNormalizer();
    $array = $normalizer->toScalar($obj);
    expect($array)->toBe(['email' => null]);
    $restored = $normalizer->fromScalar($array, MaybeEmail::class);
    expect($restored)->toEqual($obj);
});

test('denormalize array of value objects', function () {
    $data = ['a@b.com', 'c@d.com'];
    $normalizer = new UltraNormalizer();
    $result = $normalizer->fromScalar($data, EmailList::class);
    expect($result)->toBeInstanceOf(EmailList::class);
    expect($result->emails)->toHaveCount(2);
    expect($result->emails)->toContainOnlyInstancesOf(Email::class);
    expect($result->emails[0]->value)->toBe('a@b.com');
    expect($result->emails[1]->value)->toBe('c@d.com');
});

test('UltraNormalizer handles DateTimeImmutable', function () {
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
    expect($normalized['createdAt'])->toBe('2024-06-01T12:34:56+00:00');

    $denormalized = $normalizer->fromScalar($normalized, Complex::class);
    expect($denormalized->createdAt)->toBeInstanceOf(\DateTimeImmutable::class);
    expect($denormalized->createdAt->format(DATE_ATOM))->toBe($date->format(DATE_ATOM));
});

test('deeply nested structure', function () {
    $user = new User('deep', new Address('42 Galaxy Way', 'Cosmos', '424242'));
    $emails = new EmailList([new Email('deep@space.com'), new Email('void@space.com')]);
    $deep = new DeepNest($user, $emails);

    $normalizer = new UltraNormalizer();
    $array = $normalizer->toScalar($deep);

    expect($array)->toBe([
        'user' => [
            'username' => 'deep',
            'address' => [
                'street' => '42 Galaxy Way',
                'city' => 'Cosmos',
                'zip' => '424242',
            ],
        ],
        'emails' => ['deep@space.com', 'void@space.com'],
    ]);

    $restored = $normalizer->fromScalar($array, DeepNest::class);
    expect($restored)->toEqual($deep);
});

test('array of arrays', function () {
    $matrix = [
        [1, 2, 3],
        [4, 5, 6],
        [7, 8, 9],
    ];
    $obj = new ArrayOfArrays($matrix);
    $normalizer = new UltraNormalizer();
    $array = $normalizer->toScalar($obj);
    expect($array)->toBe(['matrix' => $matrix]);
    $restored = $normalizer->fromScalar($array, ArrayOfArrays::class);
    expect($restored)->toEqual($obj);
});

test('array of array of object', function () {
    $matrix = [
        [new Address('A1', 'C1', 'Z1'), new Address('A2', 'C2', 'Z2')],
        [new Address('B1', 'D1', 'Y1'), new Address('B2', 'D2', 'Y2')],
    ];
    $obj = new ArrayOfArrayOfObject($matrix);
    $normalizer = new UltraNormalizer();
    $array = $normalizer->toScalar($obj);
    expect($array)->toBe([
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
    ]);
    $restored = $normalizer->fromScalar($array, ArrayOfArrayOfObject::class);
    expect($restored)->toEqual($obj);
})->todo();

test('nested value object as value', function () {
    $obj = new OuterValue(new InnerValue(42));
    $normalizer = new UltraNormalizer();
    $array = $normalizer->toScalar($obj);
    // If the generator is naive, it will just output the InnerValue object, not its scalar value.
    expect($array)->toBe(42);

    $restored = $normalizer->fromScalar($array, OuterValue::class);
    expect($restored)->toEqual($obj);
});

test('backed enum round trip', function () {
    $normalizer = new UltraNormalizer();
    $obj = new EnumHolder(Status::ACTIVE);
    $array = $normalizer->toScalar($obj);
    expect($array)->toBe(['status' => 'active', 'nullableStatus' => null]);
    $restored = $normalizer->fromScalar($array, EnumHolder::class);
    expect($restored)->toEqual(new EnumHolder(Status::ACTIVE));
});

test('circular reference throws', function () {
    $a = new CircularA();
    $b = new CircularB($a);
    $a->b = $b;
    $normalizer = new UltraNormalizer();
    $normalizer->toScalar($a);
})->throws(CircularReferenceException::class);

test('nullable union round trip', function () {
    $normalizer = new UltraNormalizer();
    $obj = new MaybeEmail(new Email('foo@bar.com'));
    $array = $normalizer->toScalar($obj);
    expect($array)->toBe(['email' => 'foo@bar.com']);
    $restored = $normalizer->fromScalar($array, MaybeEmail::class);
    expect($restored)->toEqual(new MaybeEmail(new Email('foo@bar.com')));

    $objNull = new MaybeEmail(null);
    $arrayNull = $normalizer->toScalar($objNull);
    expect($arrayNull)->toBe(['email' => null]);
    $restoredNull = $normalizer->fromScalar($arrayNull, MaybeEmail::class);
    expect($restoredNull)->toEqual(new MaybeEmail(null));
});

test('multi type union round trip', function () {
    $normalizer = new UltraNormalizer();
    $objEmail = new MultiUnion(new Email('bar@baz.com'));
    $arrayEmail = $normalizer->toScalar($objEmail);
    expect($arrayEmail)->toBe(['value' => 'bar@baz.com']);
    $restoredEmail = $normalizer->fromScalar($arrayEmail, MultiUnion::class);
    expect($restoredEmail)->toEqual(new MultiUnion(new Email('bar@baz.com')));

    $objInt = new MultiUnion(42);
    $arrayInt = $normalizer->toScalar($objInt);
    expect($arrayInt)->toBe(['value' => 42]);
    $restoredInt = $normalizer->fromScalar($arrayInt, MultiUnion::class);
    expect($restoredInt)->toEqual(new MultiUnion(42));

    $objNull = new MultiUnion(null);
    $arrayNull = $normalizer->toScalar($objNull);
    expect($arrayNull)->toBe(['value' => null]);
    $restoredNull = $normalizer->fromScalar($arrayNull, MultiUnion::class);
    expect($restoredNull)->toEqual(new MultiUnion(null));
});

test('union invalid data throws', function () {
    $normalizer = new UltraNormalizer();
    $normalizer->fromScalar(['value' => ['not' => 'valid']], MultiUnion::class);
})->throws(DenormalizationException::class);

test('promoted and non promoted properties', function () {
    // Class with both promoted and non-promoted public properties
    // e.g. public string $promoted, public string $nonPromoted
    require_once __DIR__.'/Fixtures/PromotedAndNonPromoted.php';
    $obj = new PromotedAndNonPromoted('foo');
    $obj->nonPromoted = 'bar';
    $normalizer = new UltraNormalizer();
    $array = $normalizer->toScalar($obj);
    expect($array)->toEqual([
        'promoted' => 'foo',
        'nonPromoted' => 'bar',
    ]);
    $restored = $normalizer->fromScalar($array, PromotedAndNonPromoted::class);
    expect($restored)->toEqual($obj);
});

test('type mismatch throws with path', function () {
    $normalizer = new UltraNormalizer();
    $data = ['id' => 'not-an-int', 'name' => 'bar']; // id should be int

    try {
        $normalizer->fromScalar($data, Foo::class);
        $this->fail('Expected DenormalizationException');
    } catch (DenormalizationException $e) {
        expect($e->getMessage())->toContain('id')
            ->and($e->getMessage())->toContain('expected int')
            ->and($e->getMessage())->toContain('[id]')
        ;
    }
});

test('array map', function () {
    $result = array_map(fn ($v) => $context = new \stdClass() ? $v : null, [1, 2, 3]);
    expect($result)->toBe([1, 2, 3]);
});

test('collection denormalization type error', function () {
    $normalizer = new UltraNormalizer();
    $data = ['a@b.com', 123]; // 123 is not a valid Email

    try {
        $normalizer->fromScalar($data, EmailList::class);
        $this->fail('Expected DenormalizationException');
    } catch (DenormalizationException $e) {
        expect($e->getMessage())->toContain('expected', '[1]');
    }
});

test('nested object property type mismatch', function () {
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
        $this->fail('Expected DenormalizationException');
    } catch (DenormalizationException $e) {
        expect($e->getMessage())->toContain('zip', 'expected string', '[address][zip]');
    }
});

test('deeply nested structure type mismatch', function () {
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
        $this->fail('Expected DenormalizationException');
    } catch (DenormalizationException $e) {
        expect($e->getMessage())->toContain('zip', 'expected string', '[user][address][zip]');
    }
});

test('missing required property throws with path', function () {
    $normalizer = new UltraNormalizer();
    $data = ['name' => 'bar']; // missing id

    try {
        $normalizer->fromScalar($data, Foo::class);
        $this->fail('Expected DenormalizationException');
    } catch (DenormalizationException $e) {
        expect($e->getMessage())->toContain('id');
        expect($e->getMessage())->toContain('Missing required property');
        expect($e->getMessage())->toContain('[id]');
    }
});

test('union type mismatch throws with path', function () {
    $normalizer = new UltraNormalizer();
    $data = ['value' => ['not' => 'valid']]; // MultiUnion expects Email|int|null

    try {
        $normalizer->fromScalar($data, MultiUnion::class);
        $this->fail('Expected DenormalizationException');
    } catch (DenormalizationException $e) {
        expect($e->getMessage())->toContain('value');
        expect($e->getMessage())->toContain('does not match any type');
        expect($e->getMessage())->toContain('[value]');
    }
});

test('runtime normalizer priority', function () {
    $normalizer = new UltraNormalizer();
    $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());
    $runtimeEmail = new RuntimeEmail('foo@bar.com');
    $array = $normalizer->toScalar($runtimeEmail);
    expect($array)->toBe('runtime-email:foo@bar.com');
    $restored = $normalizer->fromScalar('runtime-email:foo@bar.com', $runtimeEmail::class);
    expect($restored)->toEqual($runtimeEmail);
});

test('runtime normalizer called for nested property', function () {
    $normalizer = new UltraNormalizer();
    $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());
    $customer = new CustomerWithRuntimeEmail('Jane', new RuntimeEmail('jane@doe.com'), '+987654321');
    $array = $normalizer->toScalar($customer);
    expect($array)->toBe([
        'name' => 'Jane',
        'email' => 'runtime-email:jane@doe.com',
        'phone' => '+987654321',
    ]);
    $restored = $normalizer->fromScalar($array, CustomerWithRuntimeEmail::class);
    expect($restored)->toEqual($customer);
});
