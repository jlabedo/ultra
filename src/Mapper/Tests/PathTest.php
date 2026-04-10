<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Mapper\Path;

/**
 * @internal
 */
#[CoversClass(Path::class)]
final class PathTest extends TestCase
{
    public function test_it_can_add_a_variable_to_an_array_string(): void
    {
        $path = Path::new()
            ->add('foo')
            ->add('bar')
            ->add(42)
        ;

        self::assertSame("['foo', 'bar', 42, \$k]", $path->toArrayString(['$k']));
    }
}
