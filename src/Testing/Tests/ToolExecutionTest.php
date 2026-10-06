<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** @internal */
#[Group('slow')]
final class ToolExecutionTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function outcomes(): iterable
    {
        yield 'passing test' => [12, 0];
        yield 'failing test' => [13, 1];
    }

    #[DataProvider('outcomes')]
    public function test_phpunit_preserves_interpreter_options_arguments_and_exit_status(int $expectedPrecision, int $expectedExitCode): void
    {
        $directory = sys_get_temp_dir().'/ultra_tool_proxy_'.bin2hex(random_bytes(8));
        mkdir($directory);
        $testFile = $directory.'/ToolProxyProbeTest.php';

        try {
            file_put_contents($testFile, <<<'PHP'
                <?php

                final class ToolProxyProbeTest extends \PHPUnit\Framework\TestCase
                {
                    public function test_interpreter_options(): void
                    {
                        self::assertSame('137M', ini_get('memory_limit'));
                        self::assertSame(getenv('ULTRA_TOOL_PROXY_EXPECTED_PRECISION'), ini_get('precision'));
                    }
                }
                PHP);

            $process = new Process([
                PHP_BINARY,
                '-d', 'memory_limit=137M',
                '-d', 'precision=12',
                'bin/phpunit',
                '--no-configuration',
                '--no-extensions',
                '--colors=never',
                $testFile,
            ], dirname(__DIR__, 3), [
                'PAO_DISABLE' => '1',
                'ULTRA_TOOL_PROXY_EXPECTED_PRECISION' => (string) $expectedPrecision,
            ]);

            self::assertSame($expectedExitCode, $process->run(), $process->getOutput().$process->getErrorOutput());
            self::assertStringContainsString(0 === $expectedExitCode ? 'OK (1 test, 2 assertions)' : 'FAILURES!', $process->getOutput());
        } finally {
            if (is_file($testFile)) {
                unlink($testFile);
            }
            rmdir($directory);
        }
    }
}
