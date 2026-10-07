<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** @internal Functional audit tests spawn multiple PHP processes per scenario. */
#[Group('slow')]
final class PhpunitAuditTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    public function test_default_audit_combines_datasets_before_taking_medians_and_groups_functional_contexts(): void
    {
        $root = $this->fixture($this->timings());
        $process = $this->audit($root);
        $exitCode = $process->run();
        $output = $process->getOutput();
        $diagnostics = $process->getErrorOutput();

        static::assertSame(0, $exitCode, $output . $diagnostics);
        static::assertStringContainsString("PHPUnit timing audit\n====================", $output);
        static::assertStringContainsString('3 methods; 4 cases per run; 3 runs', $output);
        static::assertMatchesRegularExpression(
            '/MappingTest::test_mapping[^\n]*\n\s+1\s+11\.000\s+1\s+11\.000\s+/m',
            $output,
        );
        static::assertMatchesRegularExpression(
            '/EventTest::test_rows[^\n]*\n\s+2\s+10\.000\s+2\s+9\.000\s+100\.0% \*\s+OK\s*$/m',
            $output,
        );
        static::assertStringContainsString('src/EventSourcing/Tests/Postgres/EventTest.php:42', $output);
        static::assertMatchesRegularExpression('/Event sourcing\s+12\.000\s+52\.2%/', $output);
        static::assertMatchesRegularExpression('/Mapping\s+11\.000\s+47\.8%/', $output);
        static::assertMatchesRegularExpression('/Run\s+Duration \(s\)\s+Failed\/errors\s+Skipped/', $output);
        static::assertMatchesRegularExpression('/#\s+Median\s+Cases\s+Slowest\s+Spread\s+Status/', $output);
        static::assertMatchesRegularExpression('/Context\s+Total \(s\)\s+Share of timed cases/', $output);
        static::assertStringNotContainsString('agent-facing PHPUnit output', $output);
        static::assertStringNotContainsString('agent-facing PHPUnit output', $diagnostics);
        static::assertStringNotContainsString("\033[", $output);
        static::assertStringContainsString('Run 1/3', $diagnostics);
        static::assertStringContainsString('Run 2/3', $diagnostics);
        static::assertStringContainsString('Run 3/3', $diagnostics);
        static::assertCount(3, $this->invocations($root));
        $this->assertReportsWereRemoved($root);
    }

    public function test_run_count_top_limit_and_phpunit_arguments_are_configurable(): void
    {
        $root = $this->fixture($this->timings());
        $process = $this->audit($root, ['--runs', '2', '--top=1', '--', '--filter=Fixture']);

        static::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        $output = $process->getOutput();
        static::assertStringContainsString('3 methods; 4 cases per run; 2 runs', $output);
        static::assertMatchesRegularExpression(
            '/EventTest::test_rows[^\n]*\n\s+1\s+15\.000\s+2\s+13\.500\s+/m',
            $output,
        );
        static::assertStringNotContainsString('MappingTest::test_mapping', $output);
        static::assertStringNotContainsString('CompileTest::test_compile', $output);
        static::assertMatchesRegularExpression('/Event sourcing\s+17\.000/', $output);
        foreach ($this->invocations($root) as $arguments) {
            static::assertContains('--filter=Fixture', $arguments);
        }
        $this->assertReportsWereRemoved($root);
    }

    public function test_failures_and_skips_are_reported_and_phpunit_exit_status_is_preserved(): void
    {
        $root = $this->fixture([
            [
                'xml' => '<testsuites><testsuite><testcase class="FailedTest" name="test_failure" file="src/Schema/Tests/FailedTest.php" line="10" time="0.1"><failure message="failure"/></testcase><testcase class="SkippedTest" name="test_skip" file="src/Time/Tests/SkippedTest.php" time="0"><skipped/></testcase></testsuite></testsuites>',
                'exit' => 2,
            ],
        ]);
        $process = $this->audit($root, ['--runs=1']);

        static::assertSame(2, $process->run());
        static::assertStringContainsString('[WARNING] PHPUnit returned a nonzero exit status', $process->getOutput());
        static::assertMatchesRegularExpression('/FailedTest::test_failure[^\n]*\n[^\n]+FAILED/', $process->getOutput());
        static::assertMatchesRegularExpression('/SkippedTest::test_skip[^\n]*\n[^\n]+SKIPPED/', $process->getOutput());
        static::assertMatchesRegularExpression('/^\s+1\s+[0-9.]+\s+1\s+1\s*$/m', $process->getOutput());
        static::assertStringContainsString('agent-facing PHPUnit output', $process->getErrorOutput());
        $this->assertReportsWereRemoved($root);
    }

    public function test_changed_test_selection_is_rejected_instead_of_producing_a_misleading_ranking(): void
    {
        $passages = $this->timings();
        $passages[1]['xml'] = str_replace('test_rows', 'test_other', $passages[1]['xml']);
        $root = $this->fixture($passages);
        $process = $this->audit($root);

        static::assertSame(1, $process->run());
        static::assertStringContainsString('test cases changed between runs', $process->getErrorOutput());
        static::assertStringNotContainsString('Slowest methods', $process->getOutput());
        $this->assertReportsWereRemoved($root);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidOptions(): iterable
    {
        yield 'zero runs' => [['--runs=0']];
        yield 'negative top' => [['--top=-1']];
        yield 'missing value' => [['--runs']];
        yield 'noninteger' => [['--runs=1.5']];
        yield 'overflow' => [['--runs=999999999999999999999']];
        yield 'unknown option' => [['--filter=Fixture']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidOptions')]
    public function test_invalid_options_fail_before_launching_phpunit(array $arguments): void
    {
        $root = $this->fixture($this->timings());
        $process = $this->audit($root, $arguments);

        static::assertSame(1, $process->run());
        static::assertNotSame('', trim($process->getErrorOutput()));
        static::assertFileDoesNotExist($root . '/invocations.json');
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidReports(): iterable
    {
        yield 'missing report' => [null];
        yield 'malformed report' => ['<testsuites>'];
        yield 'no cases' => ['<testsuites/>'];
        yield 'invalid time' => ['<testsuites><testcase class="Test" name="test_a" time="-1"/></testsuites>'];
    }

    #[DataProvider('invalidReports')]
    public function test_unusable_junit_reports_fail_without_an_audit_ranking(?string $xml): void
    {
        $root = $this->fixture([['xml' => $xml, 'exit' => 0]]);
        $process = $this->audit($root, ['--runs=1']);

        static::assertSame(1, $process->run());
        static::assertStringNotContainsString('Slowest methods', $process->getOutput());
        $this->assertReportsWereRemoved($root);
    }

    public function test_help_works_without_installed_dependencies_and_measurements_require_them(): void
    {
        $root = $this->fixture($this->timings());
        unlink($root . '/tools/vendor/bin/phpunit');
        $help = $this->audit($root, ['--help']);
        static::assertSame(0, $help->run());
        static::assertStringContainsString('--runs=RUNS', $help->getOutput());
        static::assertStringContainsString('--top=TOP', $help->getOutput());
        static::assertStringContainsString('[default: "3"]', $help->getOutput());
        static::assertStringContainsString('[default: "20"]', $help->getOutput());

        $audit = $this->audit($root);
        static::assertSame(1, $audit->run());
        static::assertStringContainsString('Run composer install before auditing', $audit->getErrorOutput());
        static::assertFileDoesNotExist($root . '/invocations.json');
    }

    public function test_console_styles_preserve_literal_source_paths_and_context_names(): void
    {
        $root = $this->fixture([
            [
                'xml' => '<testsuites><testcase class="Vendor\Cases\CaseTest" name="test_one" file="src/Package&lt;info&gt;/Tests/My (test) #1.php" line="12" time="0.05"/></testsuites>',
                'exit' => 0,
            ],
        ]);
        $process = $this->audit($root, ['--runs=1', '--ansi']);

        static::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        static::assertStringContainsString("\033[", $process->getOutput());
        $plain = preg_replace('/\033\[[0-9;]*m/', '', $process->getOutput());
        static::assertStringContainsString('CaseTest::test_one', $plain);
        static::assertStringContainsString('src/Package<info>/Tests/My (test) #1.php:12', $plain);
        static::assertMatchesRegularExpression('/Unmapped \(Package<info>\)\s+0\.050\s+100\.0%/', $plain);
        static::assertStringNotContainsString('agent-facing PHPUnit output', $process->getOutput());
    }

    public function test_verbose_mode_streams_phpunit_output_to_stderr(): void
    {
        $root = $this->fixture($this->timings());
        $process = $this->audit($root, ['--runs=1', '-v']);

        static::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        static::assertStringNotContainsString('agent-facing PHPUnit output', $process->getOutput());
        static::assertStringContainsString('agent-facing PHPUnit output', $process->getErrorOutput());
    }

    public function test_ranking_table_fits_an_eighty_column_terminal(): void
    {
        $root = $this->fixture($this->timings());
        $process = $this->audit($root, ['--runs=1', '--top=1']);
        $process->setEnv(['PAO_FORCE' => '1', 'COLUMNS' => '80']);

        static::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        $ranking = explode("Slowest methods\n", $process->getOutput())[1];
        $inTable = false;
        foreach (explode("\n", $ranking) as $line) {
            if (str_starts_with($line, ' ---')) {
                $inTable = true;
            }
            if ($inTable && '' === $line) {
                break;
            }
            if ($inTable) {
                static::assertLessThanOrEqual(80, mb_strlen($line), $line);
            }
        }
        static::assertTrue($inTable);
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($directory);
        }
        parent::tearDown();
    }

    /** @return list<array{xml: string, exit: int}> */
    private function timings(): array
    {
        $passages = [];
        foreach ([[1, 9, 11], [2, 18, 1], [4, 6, 11]] as [$first, $second, $mapping]) {
            $passages[] = [
                'exit' => 0,
                'xml' => sprintf(
                    '<testsuites><testsuite><testcase class="EventTest" name="test_rows with data set &quot;first&quot;" file="%%ROOT%%/src/EventSourcing/Tests/Postgres/EventTest.php" line="42" time="%d"/><testsuite><testcase class="EventTest" name="test_rows with data set #1" file="%%ROOT%%/src/EventSourcing/Tests/Postgres/EventTest.php" line="42" time="%d"/><testcase class="MappingTest" name="test_mapping" file="src/Mapper/Tests/Compiler/MappingTest.php" time="%d"/><testcase class="CompileTest" name="test_compile" file="./src/EventSourcing/Tests/Compiler/CompileTest.php" time="2"/></testsuite></testsuite></testsuites>',
                    $first,
                    $second,
                    $mapping,
                ),
            ];
        }

        return $passages;
    }

    /** @param list<array{xml: ?string, exit: int}> $passages */
    private function fixture(array $passages): string
    {
        $root = sys_get_temp_dir() . '/ultra_phpunit_audit_fixture_' . bin2hex(random_bytes(8));
        $this->directories[] = $root;
        foreach (['bin', 'tools/src', 'tools/vendor/bin', 'vendor'] as $directory) {
            mkdir($root . '/' . $directory, 0o777, true);
        }
        $repository = dirname(__DIR__, 3);
        copy($repository . '/bin/phpunit-audit', $root . '/bin/phpunit-audit');
        copy($repository . '/tools/src/PhpunitAudit.php', $root . '/tools/src/PhpunitAudit.php');
        file_put_contents(
            $root . '/tools/vendor/autoload.php',
            '<?php require '
            . var_export($repository . '/tools/vendor/autoload.php', true)
            . '; require '
            . var_export($root . '/tools/src/PhpunitAudit.php', true)
            . ';',
        );
        touch($root . '/vendor/autoload.php');
        touch($root . '/tools/vendor/bin/phpunit');
        file_put_contents($root . '/passages.json', json_encode($passages, JSON_THROW_ON_ERROR));
        file_put_contents($root . '/bin/phpunit', <<<'PHP'
            <?php
            if (getenv('PAO_FORCE') !== '1' || getcwd() !== dirname(__DIR__)) {
                exit(99);
            }
            $root = dirname(__DIR__);
            $invocations = is_file($root.'/invocations.json') ? json_decode(file_get_contents($root.'/invocations.json'), true) : [];
            $passages = json_decode(file_get_contents($root.'/passages.json'), true);
            $passage = $passages[count($invocations)];
            $invocations[] = array_slice($argv, 1);
            file_put_contents($root.'/invocations.json', json_encode($invocations));
            $report = $argv[array_search('--log-junit', $argv, true) + 1];
            if ($passage['xml'] !== null) {
                file_put_contents($report, str_replace('%ROOT%', htmlspecialchars($root, ENT_XML1), $passage['xml']));
            }
            echo "agent-facing PHPUnit output\n";
            exit($passage['exit']);
            PHP);

        return $root;
    }

    /** @param list<string> $arguments */
    private function audit(string $root, array $arguments = []): Process
    {
        $color = in_array('--ansi', $arguments, true) ? '--ansi' : '--no-ansi';

        return new Process([PHP_BINARY, $root . '/bin/phpunit-audit', $color, ...$arguments], sys_get_temp_dir(), [
            'PAO_FORCE' => '1',
            'COLUMNS' => '200',
        ]);
    }

    /** @return list<list<string>> */
    private function invocations(string $root): array
    {
        return json_decode(file_get_contents($root . '/invocations.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertReportsWereRemoved(string $root): void
    {
        foreach ($this->invocations($root) as $arguments) {
            self::assertFileDoesNotExist($arguments[array_search('--log-junit', $arguments, true) + 1]);
        }
    }
}
