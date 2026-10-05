<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Ultra\Mapper\Exception\CircularReferenceException;
use Ultra\Mapper\Tests\Fixtures\CircularA;
use Ultra\Mapper\Tests\Fixtures\CircularB;
use Ultra\Mapper\Tests\Support\MapperTestCase;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NormalizationSafetyTest extends MapperTestCase
{
    public function test_circular_references_are_rejected_and_traversal_resets_after_failure(): void
    {
        $object = new CircularA();
        $object->b = new CircularB($object);
        $normalizer = $this->normalizer();

        try {
            $normalizer->toScalar($object);
            self::fail('Expected a circular reference to be rejected.');
        } catch (CircularReferenceException $exception) {
            self::assertStringContainsString('Circular reference', $exception->getMessage());
        }

        $object->b = null;
        self::assertSame(['b' => null], $normalizer->toScalar($object));
        self::assertSame(['b' => null], $normalizer->toScalar($object));
    }
}
