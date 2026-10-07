<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

use Ultra\Mapper\Attribute\AsValue;
use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\Exception\DenormalizationException;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper\UltraNormalizer;

readonly class Address
{
    public function __construct(
        public string $street,
        public string $city,
        public string $zip,
    ) {}
}

readonly class ArrayOfArrayOfObject
{
    /**
     * @param list<list<Address>> $matrix
     */
    public function __construct(
        public array $matrix,
    ) {}
}

readonly class ArrayOfArrays
{
    /**
     * @param int[][] $matrix
     */
    public function __construct(
        public array $matrix,
    ) {}
}

readonly class Complex
{
    public function __construct(
        public string $name,
        public int $age,
        public float $score,
        public bool $active,
        public Address $address,
        public array $tags,
        public \DateTimeImmutable $createdAt,
    ) {}
}

readonly class Customer
{
    public function __construct(
        public string $name,
        public Email $email,
        public string $phone,
    ) {}
}

readonly class DeepNest
{
    public function __construct(
        public User $user,
        public EmailList $emails,
    ) {}
}

#[AsValue]
readonly class Email implements \Stringable
{
    public function __construct(
        public string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

#[AsValue]
readonly class EmailList
{
    /**
     * @param Email[] $emails
     */
    public function __construct(
        public array $emails,
    ) {}
}

class EnumHolder
{
    public function __construct(
        public Status $status,
        public ?Status $nullableStatus = null,
    ) {}
}

readonly class Foo
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}

#[AsValue]
readonly class InnerValue
{
    public function __construct(
        public int $inner,
    ) {}
}

readonly class MaybeEmail
{
    public function __construct(
        public ?Email $email,
    ) {}
}

#[AsValue]
readonly class OuterValue
{
    public function __construct(
        public InnerValue $outer,
    ) {}
}

class PromotedAndNonPromoted
{
    public string $nonPromoted;

    public function __construct(
        public string $promoted,
    ) {}
}

readonly class RuntimeEmail implements \Stringable
{
    public function __construct(
        public string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

class RuntimeEmailNormalizer implements RuntimeNormalizerInterface
{
    public static function handledTypes(): iterable
    {
        return [RuntimeEmail::class];
    }

    public function toScalar(mixed $value, UltraNormalizer $normalizer): array|bool|float|int|string|null
    {
        return 'runtime-email:' . $value->value;
    }

    public function fromScalar(
        array|bool|float|int|string|null $value,
        string $type,
        UltraNormalizer $normalizer,
        DenormalizationContext $context,
    ): mixed {
        if (!is_string($value) || !str_starts_with($value, 'runtime-email:')) {
            throw new DenormalizationException('expected runtime email at path ' . $context->path);
        }

        return new RuntimeEmail(substr($value, strlen('runtime-email:')));
    }
}

enum Status: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}

readonly class User
{
    public function __construct(
        public string $username,
        public Address $address,
    ) {}
}

class CircularA
{
    public function __construct(
        public ?CircularB $b = null,
    ) {}
}

class CircularB
{
    public function __construct(
        public ?CircularA $a = null,
    ) {}
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

readonly class SharedRuntimeEmails
{
    public function __construct(
        public RuntimeEmail $primary,
        public RuntimeEmail $secondary,
    ) {}
}

#[AsValue]
final class HookedEmail
{
    public string $domain {
        get => substr($this->value, (int) strpos($this->value, '@') + 1);
    }

    public function __construct(
        public readonly string $value,
    ) {}
}

final class HookedSlot
{
    public string $day {
        get => substr($this->start, 0, 10);
    }

    public function __construct(
        public readonly string $start,
        public readonly HookedEmail $owner,
    ) {}
}

final class CompoundId
{
    public static int $version = 1;

    public function __construct(
        public int $right,
        public string $left,
    ) {}
}

final class HookedId
{
    public string $label {
        get => $this->left . '-' . $this->right;
    }

    public function __construct(
        public readonly string $left,
        public readonly int $right,
    ) {}
}

final class ExternalId {}

final class ExternalIdNormalizer implements RuntimeNormalizerInterface
{
    public static function handledTypes(): iterable
    {
        return [ExternalId::class];
    }

    public function toScalar(mixed $value, UltraNormalizer $normalizer): int|string
    {
        return 'external';
    }

    public function fromScalar(
        array|bool|float|int|string|null $value,
        string $type,
        UltraNormalizer $normalizer,
        DenormalizationContext $context,
    ): mixed {
        return new ExternalId();
    }
}

final class CompoundIdNormalizer implements RuntimeNormalizerInterface
{
    public static function handledTypes(): iterable
    {
        return [CompoundId::class];
    }

    /**
     * @return array{
     *     left: string,
     *     right: string|int,
     * }
     */
    public function toScalar(mixed $value, UltraNormalizer $normalizer): array
    {
        return ['left' => 'left', 'right' => 1];
    }

    public function fromScalar(
        array|bool|float|int|string|null $value,
        string $type,
        UltraNormalizer $normalizer,
        DenormalizationContext $context,
    ): mixed {
        return new CompoundId(1, 'left');
    }
}

final class ImpreciseIdNormalizer implements RuntimeNormalizerInterface
{
    public static function handledTypes(): iterable
    {
        return [ExternalId::class];
    }

    public function toScalar(mixed $value, UltraNormalizer $normalizer): array|bool|float|int|string|null
    {
        return 'external';
    }

    public function fromScalar(
        array|bool|float|int|string|null $value,
        string $type,
        UltraNormalizer $normalizer,
        DenormalizationContext $context,
    ): mixed {
        return new ExternalId();
    }
}

readonly class DerivedRuntimeEmail extends RuntimeEmail {}

readonly class SecondaryRuntimeEmail extends RuntimeEmail {}

readonly class AssemblyRecord
{
    public function __construct(
        public RuntimeEmail $primary,
        public SecondaryRuntimeEmail $secondary,
    ) {}
}

class MultiTypeRuntimeEmailNormalizer extends RuntimeEmailNormalizer
{
    public static function handledTypes(): iterable
    {
        return [RuntimeEmail::class, SecondaryRuntimeEmail::class];
    }

    public function fromScalar(
        array|bool|float|int|string|null $value,
        string $type,
        UltraNormalizer $normalizer,
        DenormalizationContext $context,
    ): mixed {
        return new $type(substr((string) $value, strlen('runtime-email:')));
    }
}

class UntypedId
{
    public $id;
}

class NullableId
{
    public ?int $id;
}

class ObjectUnionId
{
    public Email|int $id;
}

#[AsValue]
class BrokenValue
{
    public int $left;
    public int $right;
}

enum UnitEnum
{
    case ACTIVE;
}

abstract class ArrayShapeNormalizer implements RuntimeNormalizerInterface
{
    public static function handledTypes(): iterable
    {
        return [ExternalId::class];
    }

    public function fromScalar(
        array|bool|float|int|string|null $value,
        string $type,
        UltraNormalizer $normalizer,
        DenormalizationContext $context,
    ): mixed {
        return new ExternalId();
    }
}

class UndocumentedArrayNormalizer extends ArrayShapeNormalizer
{
    public function toScalar(mixed $value, UltraNormalizer $normalizer): array
    {
        return ['id' => 1];
    }
}

class MalformedArrayNormalizer extends ArrayShapeNormalizer
{
    /** @return array{bad-key: int} */
    public function toScalar(mixed $value, UltraNormalizer $normalizer): array
    {
        return ['bad-key' => 1];
    }
}

namespace Ultra\Mapper\Tests\Fixtures\Collision;

readonly class A_B
{
    public function __construct(
        public int $id,
    ) {}
}

readonly class A_sB
{
    public function __construct(
        public int $id,
    ) {}
}

namespace Ultra\Mapper\Tests\Fixtures\Collision\A;

readonly class B
{
    public function __construct(
        public string $name,
    ) {}
}

namespace Ultra\Mapper\Tests\Fixtures\Collision_A;

readonly class B
{
    public function __construct(
        public string $name,
    ) {}
}
