<?php

declare(strict_types=1);

namespace Ultra\CodeGeneration\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\CodeGeneration\GeneratedPhpClassWriter;
use Ultra\CodeGeneration\PhpCode;
use Ultra\CodeGeneration\PhpFileWriter;

#[CoversClass(PhpCode::class)]
#[CoversClass(PhpFileWriter::class)]
#[CoversClass(GeneratedPhpClassWriter::class)]
final class PhpCodeTest extends TestCase
{
    public function test_formats_class_literals(): void
    {
        self::assertSame('\\App\\Thing::class', PhpCode::classLiteral('App\\Thing'));
        self::assertSame('\\App\\Thing', PhpCode::fqcn('\\App\\Thing'));
    }

    public function test_indents_non_empty_lines(): void
    {
        self::assertSame("        first\n\n        second", PhpCode::indent("first\n\nsecond", 2));
        self::assertSame('        ', PhpCode::indentLevel(2));
    }

    public function test_indents_snippet_continuation_lines(): void
    {
        self::assertSame(
            "        if (\$ok) {\n            return true;\n        }",
            PhpCode::indent("if (\$ok) {\n    return true;\n}", 2),
        );

        self::assertSame(
            "call(\n        'value'\n        )",
            PhpCode::indentContinuationLines("call(\n'value'\n)", 2),
        );

        self::assertSame('call()', PhpCode::indentContinuationLines('call()', 2));
    }

    public function test_joins_non_empty_code_blocks(): void
    {
        self::assertSame(
            "\$a = 1;\nreturn \$a;",
            PhpCode::join('', '$a = 1;', '', 'return $a;'),
        );
    }

    public function test_exports_string_lists(): void
    {
        self::assertSame("['course', 'student']", PhpCode::exportStringList(['course', 'student']));
        self::assertSame("array (\n  0 => 'one',\n)", PhpCode::exportValue(['one']));
    }

    public function test_builds_php_identifiers_from_domain_names(): void
    {
        self::assertSame('course_id', PhpCode::identifier('course-id'));
        self::assertSame('tag_123', PhpCode::identifier('123', 'tag'));
        self::assertSame('tag', PhpCode::identifier('---', 'tag'));
    }

    public function test_writes_content_addressed_php_classes(): void
    {
        $outputDir = sys_get_temp_dir().'/ultra-code-generation-test-'.bin2hex(random_bytes(4));

        try {
            $result = new GeneratedPhpClassWriter()->writeContentAddressed(
                'CompiledThing',
                $outputDir,
                static fn (string $className): string => "<?php\nfinal class {$className} {}\n",
                '__CLASS__',
            );

            self::assertStringStartsWith('CompiledThing_', $result['className']);
            self::assertFileExists($result['filePath']);
            self::assertStringContainsString('final class '.$result['className'], file_get_contents($result['filePath']));
        } finally {
            array_map('unlink', glob($outputDir.'/*.php') ?: []);
            if (is_dir($outputDir)) {
                rmdir($outputDir);
            }
        }
    }
}
