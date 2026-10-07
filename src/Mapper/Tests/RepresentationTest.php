<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Ultra\Mapper\Tests\Fixtures\HookedEmail;
use Ultra\Mapper\Tests\Fixtures\HookedSlot;
use Ultra\Mapper\Tests\Fixtures\InnerValue;
use Ultra\Mapper\Tests\Fixtures\MaybeEmail;
use Ultra\Mapper\Tests\Fixtures\MultiUnion;
use Ultra\Mapper\Tests\Fixtures\OuterValue;
use Ultra\Mapper\Tests\Fixtures\PromotedAndNonPromoted;
use Ultra\Mapper\Tests\Fixtures\Status;
use Ultra\Mapper\Tests\Fixtures\User;
use Ultra\Mapper\Tests\Support\MapperTestCase;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
final class RepresentationTest extends MapperTestCase
{
    #[DataProvider('representations')]
    public function test_representations_preserve_values_in_both_directions(object $object, mixed $representation): void
    {
        $normalizer = $this->normalizer();

        static::assertSame($representation, $normalizer->toScalar($object));
        static::assertEquals($object, $normalizer->fromScalar($representation, $object::class));
    }

    /** @return iterable<string, array{object, mixed}> */
    public static function representations(): iterable
    {
        yield 'scalar properties' => [new Foo(42, 'bar'), ['id' => 42, 'name' => 'bar']];
        yield 'nested value' => [
            new Customer('Alice', new Email('alice@example.test'), '123'),
            [
                'name' => 'Alice',
                'email' => 'alice@example.test',
                'phone' => '123',
            ],
        ];
        yield 'nullable value present' => [new MaybeEmail(new Email('a@example.test')), ['email' => 'a@example.test']];
        yield 'nullable value absent' => [new MaybeEmail(null), ['email' => null]];
        yield 'union object' => [new MultiUnion(new Email('a@example.test')), ['value' => 'a@example.test']];
        yield 'union scalar' => [new MultiUnion(42), ['value' => 42]];
        yield 'union null' => [new MultiUnion(null), ['value' => null]];
        yield 'value collection preserves keys' => [
            new EmailList(['work' => new Email('a@example.test'), 7 => new Email('b@example.test')]),
            [
                'work' => 'a@example.test',
                7 => 'b@example.test',
            ],
        ];
        yield 'nested values and objects' => [
            new DeepNest(
                new User('deep', new Address('42 Galaxy Way', 'Cosmos', '424242')),
                new EmailList([new Email('deep@example.test')]),
            ),
            [
                'user' => [
                    'username' => 'deep',
                    'address' => ['street' => '42 Galaxy Way', 'city' => 'Cosmos', 'zip' => '424242'],
                ],
                'emails' => ['deep@example.test'],
            ],
        ];
        yield 'nested scalar collections' => [new ArrayOfArrays([[1, 2], [3]]), ['matrix' => [[1, 2], [3]]]];
        yield 'nested object collections' => [
            new ArrayOfArrayOfObject([[new Address('A', 'C', 'Z')], [new Address('B', 'D', 'Y')]]),
            [
                'matrix' => [
                    [['street' => 'A', 'city' => 'C', 'zip' => 'Z']],
                    [['street' => 'B', 'city' => 'D', 'zip' => 'Y']],
                ],
            ],
        ];
        yield 'date and native scalar types' => [
            new Complex(
                'Alice',
                30,
                99.5,
                true,
                new Address('Main', 'Paris', '75000'),
                [1, 2],
                new \DateTimeImmutable('2024-06-01T12:34:56+02:00'),
            ),
            [
                'name' => 'Alice',
                'age' => 30,
                'score' => 99.5,
                'active' => true,
                'address' => ['street' => 'Main', 'city' => 'Paris', 'zip' => '75000'],
                'tags' => [1, 2],
                'createdAt' => '2024-06-01T12:34:56+02:00',
            ],
        ];
        yield 'nested AsValue wrappers' => [new OuterValue(new InnerValue(42)), 42];
        yield 'backed enum and nullable enum absent' => [
            new EnumHolder(Status::ACTIVE),
            ['status' => 'active', 'nullableStatus' => null],
        ];
        yield 'nullable enum present' => [
            new EnumHolder(Status::ACTIVE, Status::INACTIVE),
            ['status' => 'active', 'nullableStatus' => 'inactive'],
        ];
        $properties = new PromotedAndNonPromoted('foo');
        $properties->nonPromoted = 'bar';
        yield 'constructor and non-promoted properties' => [$properties, ['nonPromoted' => 'bar', 'promoted' => 'foo']];
        yield 'virtual properties are derived rather than persisted' => [
            new HookedSlot('2026-09-10 10:00', new HookedEmail('ada@example.test')),
            [
                'start' => '2026-09-10 10:00',
                'owner' => 'ada@example.test',
            ],
        ];
    }
}
