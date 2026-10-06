<?php

declare(strict_types=1);

namespace Ultra\CodeGeneration\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\CodeGeneration\PhpCode;

#[CoversClass(PhpCode::class)]
final class SafeExpressionsTest extends TestCase
{
    public function test_class_references_have_one_namespace_prefix(): void
    {
        foreach (['App\\Thing', '\\App\\Thing'] as $className) {
            self::assertSame('\\App\\Thing', PhpCode::fqcn($className));
            self::assertSame('\\App\\Thing::class', PhpCode::classLiteral($className));
        }
        self::assertSame(self::class, eval('return '.PhpCode::classLiteral(self::class).';'));
    }

    public function test_literals_round_trip_through_php(): void
    {
        $values = ["quote'", 'back\\slash', "line\nbreak", '$variable'];
        $payload = ['strings' => $values, 'number' => 123, 'null' => null, 'boolean' => false];

        self::assertSame("['course', 'student']", PhpCode::exportStringList(['course', 'student']));
        self::assertSame("array (\n  0 => 'one',\n)", PhpCode::exportValue(['one']));
        self::assertSame($values, eval('return '.PhpCode::exportStringList($values).';'));
        self::assertSame($values[0], eval('return '.PhpCode::exportString($values[0]).';'));
        self::assertSame($payload, eval('return '.PhpCode::exportValue($payload).';'));
    }

    #[DataProvider('domainNames')]
    public function test_domain_names_become_usable_variable_names(string $name, string $fallback, string $expected): void
    {
        $identifier = PhpCode::identifier($name, $fallback);

        self::assertSame($expected, $identifier);
        self::assertSame('stored', eval('$'.$identifier.' = "stored"; return $'.$identifier.';'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function domainNames(): iterable
    {
        yield 'punctuation' => ['course-id', 'value', 'course_id'];
        yield 'valid name with digits' => ['course_123', 'value', 'course_123'];
        yield 'numeric name' => ['123', 'tag', 'tag_123'];
        yield 'empty normalized name' => ['---', 'tag', 'tag'];
    }
}
