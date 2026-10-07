<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\Mapper\Exception\CircularReferenceException;
use Ultra\Mapper\Tests\Fixtures\CircularA;
use Ultra\Mapper\Tests\Fixtures\CircularB;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmailNormalizer;
use Ultra\Mapper\Tests\Fixtures\SharedRuntimeEmails;
use Ultra\Mapper\Tests\Support\MapperTestCase;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
final class NormalizationSafetyTest extends MapperTestCase
{
    public function test_runtime_normalized_objects_can_be_shared_by_multiple_properties(): void
    {
        $email = new RuntimeEmail('ada@example.test');
        $object = new SharedRuntimeEmails($email, $email);
        $normalizer = $this->normalizer();
        $normalizer->registerRuntimeNormalizer(new RuntimeEmailNormalizer());
        $representation = [
            'primary' => 'runtime-email:ada@example.test',
            'secondary' => 'runtime-email:ada@example.test',
        ];

        static::assertSame($representation, $normalizer->toScalar($object));
        static::assertSame($representation, $normalizer->toScalar($object));
    }

    public function test_circular_references_are_rejected_and_traversal_resets_after_failure(): void
    {
        $object = new CircularA();
        $object->b = new CircularB($object);
        $normalizer = $this->normalizer();

        try {
            $normalizer->toScalar($object);
            static::fail('Expected a circular reference to be rejected.');
        } catch (CircularReferenceException $exception) {
            static::assertStringContainsString('Circular reference', $exception->getMessage());
        }

        $object->b = null;
        static::assertSame(['b' => null], $normalizer->toScalar($object));
        static::assertSame(['b' => null], $normalizer->toScalar($object));
    }
}
