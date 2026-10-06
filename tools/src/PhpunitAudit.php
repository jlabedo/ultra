<?php

declare(strict_types=1);

namespace UltraDev\Tools;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Process\Process;

/** Collects file-based timings without interpreting PHPUnit or PAO console output. */
final class PhpunitAudit extends SingleCommandApplication
{
    public function __construct(private readonly string $root)
    {
        parent::__construct('phpunit-audit');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Audit PHPUnit timings by method and functional context.')
            ->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Number of suite runs.', '3')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Number of slowest methods to show.', '20')
            ->addArgument('phpunit-arguments', InputArgument::IS_ARRAY, 'PHPUnit arguments, supplied after --.')
            ->setHelp('Runs bin/phpunit from the repository root, retaining its configuration and PAO. Dependency installation is excluded; JUnit logging is included in timings. Use -v to stream PHPUnit/PAO output to stderr.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $diagnostics = $io->getErrorStyle();

        try {
            $runs = self::positiveInteger($input->getOption('runs'), 'runs');
            $top = self::positiveInteger($input->getOption('top'), 'top');
            $phpunitArguments = $input->getArgument('phpunit-arguments');
            if (!is_file($this->root.'/vendor/autoload.php') || !is_file($this->root.'/tools/vendor/bin/phpunit')) {
                throw new \RuntimeException('Run composer install before auditing; dependency installation must stay outside the measurements.');
            }

            $durations = [];
            $samples = [];
            $caseNames = null;
            $exitCode = 0;

            for ($run = 1; $run <= $runs; ++$run) {
                $report = tempnam(sys_get_temp_dir(), 'ultra_phpunit_audit_');
                if (false === $report) {
                    throw new \RuntimeException('Unable to create a temporary JUnit report.');
                }

                try {
                    $diagnostics->text(sprintf('Run %d/%d', $run, $runs));
                    $process = new Process(
                        [PHP_BINARY, $this->root.'/bin/phpunit', ...$phpunitArguments, '--log-junit', $report],
                        $this->root,
                        input: STDIN,
                        timeout: null,
                    );
                    $start = hrtime(true);
                    $status = $process->run(
                        $output->isVerbose() ? static function (string $type, string $buffer) use ($diagnostics): void {
                            $diagnostics->write($buffer, false, OutputInterface::OUTPUT_RAW);
                        } : null,
                    );
                    $durations[] = (hrtime(true) - $start) / 1e9;
                    if (0 !== $status && !$output->isVerbose()) {
                        $diagnostics->write($process->getOutput().$process->getErrorOutput(), false, OutputInterface::OUTPUT_RAW);
                    }
                    if (0 !== $status && 0 === $exitCode) {
                        $exitCode = $status > 0 ? $status : 1;
                    }

                    [$methods, $names] = self::readReport($report, $this->root);
                    if (null !== $caseNames && $names !== $caseNames) {
                        throw new \RuntimeException('The test cases changed between runs; their timings cannot be compared.');
                    }
                    $caseNames = $names;
                    $samples[] = $methods;
                } finally {
                    unlink($report);
                }
            }

            self::printReport($io, $samples, $durations, $top, $exitCode);

            return $exitCode;
        } catch (\RuntimeException $exception) {
            $diagnostics->error(OutputFormatter::escape($exception->getMessage()));

            return self::FAILURE;
        }
    }

    private static function positiveInteger(mixed $value, string $option): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value) || false === filter_var($value, FILTER_VALIDATE_INT)) {
            throw new \RuntimeException('--'.$option.' must be a positive integer.');
        }

        return (int) $value;
    }

    /**
     * @return array{array<string, array{time: float, cases: int, slowest: float, failed: int, skipped: int, file: string, line: int}>, list<string>}
     */
    private static function readReport(string $path, string $root): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->load($path, LIBXML_NONET)) {
                throw new \RuntimeException('PHPUnit did not produce a valid JUnit report; no audit ranking was generated.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $methods = [];
        $names = [];
        foreach ($document->getElementsByTagName('testcase') as $case) {
            $class = $case->getAttribute('class');
            $name = $case->getAttribute('name');
            $time = $case->getAttribute('time');
            if ('' === $class || '' === $name || !is_numeric($time) || !is_finite((float) $time) || (float) $time < 0) {
                throw new \RuntimeException('A JUnit test case has no valid identity or duration.');
            }
            $file = str_replace('\\', '/', $case->getAttribute('file'));
            if (str_starts_with($file, $root.'/')) {
                $file = substr($file, strlen($root) + 1);
            }
            $file = preg_replace('~^(?:\./)+~', '', $file);
            $method = $class.'::'.preg_replace('/ with data set .*$/s', '', $name);
            $names[] = $class.'::'.$name.'@'.$file;
            $methods[$method] ??= ['time' => 0.0, 'cases' => 0, 'slowest' => 0.0, 'failed' => 0, 'skipped' => 0, 'file' => $file, 'line' => (int) $case->getAttribute('line')];
            $methods[$method]['time'] += (float) $time;
            ++$methods[$method]['cases'];
            $methods[$method]['slowest'] = max($methods[$method]['slowest'], (float) $time);
            $methods[$method]['failed'] += (int) ($case->getElementsByTagName('failure')->length > 0 || $case->getElementsByTagName('error')->length > 0);
            $methods[$method]['skipped'] += (int) ($case->getElementsByTagName('skipped')->length > 0);
        }
        if ([] === $methods) {
            throw new \RuntimeException('The JUnit report contains no test cases.');
        }
        sort($names);

        return [$methods, $names];
    }

    /**
     * @param list<array<string, array{time: float, cases: int, slowest: float, failed: int, skipped: int, file: string, line: int}>> $samples
     * @param list<float> $durations
     */
    private static function printReport(SymfonyStyle $io, array $samples, array $durations, int $top, int $exitCode): void
    {
        $rows = [];
        $contexts = [];
        $unattributed = [];
        foreach ($samples as $index => $sample) {
            $unattributed[] = $durations[$index] - array_sum(array_column($sample, 'time'));
        }
        foreach ($samples[0] as $name => $first) {
            $times = array_map(static fn (array $sample): float => $sample[$name]['time'], $samples);
            $time = self::median($times);
            $spread = $time > 0 ? (max($times) - min($times)) / $time * 100 : (max($times) > 0 ? INF : 0.0);
            $failed = array_sum(array_map(static fn (array $sample): int => $sample[$name]['failed'], $samples));
            $skipped = array_sum(array_map(static fn (array $sample): int => $sample[$name]['skipped'], $samples));
            $rows[$name] = [
                ...$first,
                'time' => $time,
                'slowest' => self::median(array_map(static fn (array $sample): float => $sample[$name]['slowest'], $samples)),
                'spread' => $spread,
                'status' => $failed > 0 ? 'FAILED' : ($skipped > 0 ? 'SKIPPED' : 'OK'),
            ];
            $context = self::context($first['file']);
            $contexts[$context] = ($contexts[$context] ?? 0.0) + $time;
        }
        uasort($rows, static fn (array $left, array $right): int => $right['time'] <=> $left['time']);
        arsort($contexts);
        $total = array_sum($contexts);

        $io->title('PHPUnit timing audit');
        $io->definitionList(
            ['Command median' => sprintf('%.3f s (range: %.3f–%.3f s)', self::median($durations), min($durations), max($durations))],
            ['Sum of method medians' => sprintf('%.3f s', $total)],
            ['Median outside individual cases' => sprintf('%.3f s', self::median($unattributed))],
            ['Selection' => sprintf('%d methods; %d cases per run; %d runs', count($rows), array_sum(array_column($samples[0], 'cases')), count($samples))],
        );
        $io->note('Command durations include startup, discovery and JUnit logging. Individual case timings include setup and teardown.');
        if (0 !== $exitCode) {
            $io->warning('PHPUnit returned a nonzero exit status; these timings include an unsuccessful suite.');
        }
        $io->section('Command runs');
        $runRows = [];
        foreach ($durations as $index => $duration) {
            $runRows[] = [$index + 1, sprintf('%.3f', $duration), array_sum(array_column($samples[$index], 'failed')), array_sum(array_column($samples[$index], 'skipped'))];
        }
        $io->table(['Run', 'Duration (s)', 'Failed/errors', 'Skipped'], $runRows);
        $io->section('Slowest methods');
        $io->text('Times are in seconds. Datasets are combined before taking the median; slowest is the median of each run\'s slowest case.');
        $methodRows = [];
        $rank = 0;
        foreach (array_slice($rows, 0, $top, true) as $name => $row) {
            $separator = strrpos($name, '\\');
            $label = false !== $separator ? substr($name, $separator + 1) : $name;
            if ($rank > 0) {
                $methodRows[] = new TableSeparator();
            }
            $methodRows[] = [new TableCell('<info>'.OutputFormatter::escape($label).'</info>', ['colspan' => 6])];
            $status = match ($row['status']) {
                'FAILED' => '<error>FAILED</error>',
                'SKIPPED' => '<comment>SKIPPED</comment>',
                default => '<info>OK</info>',
            };
            $spread = sprintf('%.1f%%', $row['spread']);
            if ($row['spread'] > 20) {
                $spread = '<comment>'.$spread.' *</comment>';
            }
            $methodRows[] = [++$rank, sprintf('%.3f', $row['time']), $row['cases'], sprintf('%.3f', $row['slowest']), $spread, $status];
            if ('' !== $row['file']) {
                $source = OutputFormatter::escape($row['file'].($row['line'] > 0 ? ':'.$row['line'] : ''));
                $methodRows[] = [new TableCell('<fg=gray>Source: '.$source.'</>', ['colspan' => 6])];
            }
        }
        $columnWidth = max(7, intdiv(min(120, (new Terminal())->getWidth()) - 19, 6));
        $table = $io->createTable()
            ->setHeaders(['#', 'Median', 'Cases', 'Slowest', 'Spread', 'Status'])
            ->setRows($methodRows);
        foreach (range(0, 5) as $column) {
            $table->setColumnWidth($column, $columnWidth)->setColumnMaxWidth($column, $columnWidth);
        }
        $table->render();
        $io->newLine();
        $unstable = count(array_filter($rows, static fn (array $row): bool => $row['spread'] > 20));
        $io->text(sprintf('%d methods exceed 20%% spread: (max - min) / median. * marks variation, not a performance budget.', $unstable));

        $io->section('Functional contexts');
        $io->text('Totals sum method medians; percentages refer to timed cases, not command duration.');
        $contextRows = [];
        foreach ($contexts as $context => $time) {
            $contextRows[] = [OutputFormatter::escape($context), sprintf('%.3f', $time), sprintf('%.1f%%', $total > 0 ? $time / $total * 100 : 0.0)];
        }
        $io->table(['Context', 'Total (s)', 'Share of timed cases'], $contextRows);
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** Package ownership follows docs/README.md's task routing, including compiler adapters. */
    private static function context(string $file): string
    {
        if (str_starts_with($file, 'tests/mediator_example/')) {
            return 'Message handling';
        }
        if (str_starts_with($file, 'tests/mapper_example/')) {
            return 'Mapping';
        }
        if (str_starts_with($file, 'examples/iam/tests/')) {
            return 'IAM integration';
        }
        if (!preg_match('~^src/([^/]+)/~', $file, $match)) {
            return 'Unmapped';
        }

        return match ($match[1]) {
            'MessageHandling', 'MessageHandling_Compiler', 'MessageHandling_PostgresQueue', 'StatefulMessageHandling' => 'Message handling',
            'Events', 'EventStore', 'EventStore_Postgres', 'EventSourcedState', 'EventSourcedState_Compiler' => 'Event sourcing',
            'StateStore', 'StateStore_Bundle', 'MappedTable' => 'Stored state',
            'Mapper', 'Mapper_Compiler', 'Mapper_Bundle' => 'Mapping',
            'Projection', 'Projection_Postgres' => 'Projections',
            'Coordination', 'Coordination_Postgres' => 'Coordination',
            'UnitOfWork' => 'Transactions',
            'Schema' => 'Schema',
            'Container', 'SymfonyBundle' => 'Symfony integration',
            'CodeGeneration' => 'Code generation',
            'Time' => 'Time',
            'Testing' => 'Testing/tooling',
            default => 'Unmapped ('.$match[1].')',
        };
    }
}
