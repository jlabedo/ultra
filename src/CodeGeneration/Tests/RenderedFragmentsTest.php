<?php

declare(strict_types=1);

namespace Ultra\CodeGeneration\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\CodeGeneration\PhpCode;

#[CoversClass(PhpCode::class)]
final class RenderedFragmentsTest extends TestCase
{
    #[DataProvider('indentation')]
    public function test_indentation_preserves_text_and_empty_lines(string $method, string $code, int $levels, string $expected): void
    {
        self::assertSame($expected, (string) PhpCode::from($code)->{$method}($levels));
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function indentation(): iterable
    {
        yield 'nested and empty lines' => ['indent', "if (\$ok) {\n\n    return true;\n}", 2, "        if (\$ok) {\n\n            return true;\n        }"];
        yield 'no indentation' => ['indent', "first\n\nsecond", 0, "first\n\nsecond"];
        yield 'continuations and empty lines' => ['indentContinuationLines', "call(\n\n'value'\n)", 2, "call(\n\n        'value'\n        )"];
        yield 'two lines' => ['indentContinuationLines', "first\nsecond", 2, "first\n        second"];
        yield 'single line' => ['indentContinuationLines', 'call()', 2, 'call()'];
    }

    public function test_composed_fragments_render_an_executable_body(): void
    {
        $body = PhpCode::empty()->add('', PhpCode::join('$a = 1;', '', PhpCode::from('return $a;')));
        $renderedBody = PhpCode::from($body)->indent();
        $code = <<<PHP
            function () {
            {$renderedBody}
            }
            PHP;

        self::assertSame("\$a = 1;\nreturn \$a;", (string) $body);
        self::assertSame('        ', PhpCode::indentLevel(2));
        self::assertSame("function () {\n    \$a = 1;\n    return \$a;\n}", $code);
        $function = eval('return '.$code.';');
        self::assertSame(1, $function());
    }
}
