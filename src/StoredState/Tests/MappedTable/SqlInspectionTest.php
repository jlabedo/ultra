<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use MappedTableSqlExample\Inspection;
use MappedTableSqlExample\Inspector;
use MappedTableSqlExample\Scenario;
use MappedTableSqlExample\TextReport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\RowUpdatePlan;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\SqlTrace;
use Ultra\Testing\SqlTraceEntry;
use Ultra\Testing\SqlTraceMiddleware;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNothing]
final class SqlInspectionTest extends TestCase
{
    private Connection $connection;
    private SqlTrace $trace;
    private string $dsn;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 4) . '/examples/mapped-table-sql/bootstrap.php';
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            static::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to inspect MappedRowSet SQL.');
        }
        $this->dsn = $dsn;
        $this->trace = new SqlTrace();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new SqlTraceMiddleware($this->trace)]);
        $this->connection = PostgresConnectionSupport::connection($dsn, $configuration);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    public function test_plan_explains_merged_changes_and_trace_exposes_actual_batches_and_cleanup(): void
    {
        $inspection = $this->inspect('batch-writes');
        static::assertTrue($inspection->succeeded());
        static::assertSame(
            ['active', 'active'],
            array_column(array_column($inspection->plan->rowUpdates, 'setColumns'), 'status'),
        );
        $inserts = array_values(array_filter(
            $this->scenarioEntries($inspection),
            static fn(SqlTraceEntry $entry): bool => str_starts_with($entry->sql ?? '', 'INSERT'),
        ));
        static::assertCount(2, $inserts);
        static::assertSame(['a', 'active', 'b', 'active'], array_values($inserts[0]->parameters));
        static::assertStringContainsString('VALUES (?, ?), (?, ?)', $inserts[0]->sql);
        static::assertSame('commit', array_last($this->scenarioEntries($inspection))->operation);
        $this->assertCleanedUp($inspection);
        $report = TextReport::render($inspection);
        static::assertStringContainsString('Pending plan', $report);
        static::assertStringContainsString('[setup]', $report);
        static::assertStringContainsString('[cleanup]', $report);
        static::assertStringContainsString('Scenario: 3 SQL execution call(s)', $report);
        static::assertStringContainsString('Result: OK', $report);
    }

    public function test_check_only_scenario_keeps_pre_transaction_reads_and_locks_without_writes(): void
    {
        $inspection = $this->inspect('check-only');
        static::assertTrue($inspection->succeeded());
        static::assertSame(
            [RowUpdatePlan::MODE_EXPECT, RowUpdatePlan::MODE_EXPECT, RowUpdatePlan::MODE_EXPECT],
            array_column($inspection->plan->rowUpdates, 'mode'),
        );
        $entries = $this->scenarioEntries($inspection);
        static::assertStringStartsWith('SELECT', $entries[0]->sql);
        static::assertSame('begin', $entries[1]->operation);
        foreach ($entries as $entry) {
            if (null !== $entry->sql) {
                static::assertStringStartsWith('SELECT', $entry->sql);
            }
        }
        static::assertStringContainsString('FOR SHARE', implode('\n', array_column($entries, 'sql')));
        $this->assertCleanedUp($inspection);
    }

    public function test_conflict_retains_the_full_plan_but_only_the_executed_prefix_and_rollback(): void
    {
        $inspection = $this->inspect('optimistic-conflict');
        static::assertFalse($inspection->succeeded());
        static::assertInstanceOf(ConcurrencyException::class, $inspection->error);
        static::assertCount(3, $inspection->plan->rowUpdates);
        $entries = $this->scenarioEntries($inspection);
        $updates = array_values(array_filter($entries, static fn(SqlTraceEntry $entry): bool => str_starts_with(
            $entry->sql ?? '',
            'UPDATE',
        )));
        static::assertCount(2, $updates);
        static::assertSame(['active', 'b', 'pending'], array_values($updates[1]->parameters));
        // The SQL succeeded with zero affected rows; the adapter then reported the optimistic conflict.
        static::assertNull($updates[1]->errorClass);
        static::assertSame('rollback', array_last($entries)->operation);
        $this->assertCleanedUp($inspection);
        static::assertStringContainsString(ConcurrencyException::class, TextReport::render($inspection));
    }

    public function test_work_failure_preserves_pending_changes_and_a_later_run_starts_clean(): void
    {
        $error = new \RuntimeException('Decision failed.');
        $inspector = new Inspector($this->connection, $this->trace);
        $inspection = $inspector->run(new Scenario(static function (MappedRowSet $rows) use ($error): void {
            $rows->withKey('a')->insert()->setColumn('status', 'pending');
            throw $error;
        }));
        static::assertSame($error, $inspection->error);
        static::assertCount(1, $inspection->plan->rowUpdates);
        static::assertSame([], $this->scenarioEntries($inspection));
        $this->assertCleanedUp($inspection);
        $next = $inspector->run(new Scenario(static function (MappedRowSet $rows): void {
            $rows->withKey('next')->insert()->setColumn('status', 'active');
        }));
        static::assertTrue($next->succeeded());
        static::assertSame('next', $next->plan->rowUpdates[0]->key->value('id'));
        foreach ($next->entries as $entry) {
            static::assertStringNotContainsString($inspection->tableName, $entry->sql ?? '');
        }
        $this->assertCleanedUp($next);
    }

    public function test_setup_failure_is_reported_and_the_created_table_is_cleaned_up(): void
    {
        $error = new \RuntimeException('Preparation failed.');
        $inspection = new Inspector($this->connection, $this->trace)->run(
            new Scenario(static function (): void {}, prepare: static function () use ($error): void {
                throw $error;
            }),
        );
        static::assertSame($error, $inspection->error);
        static::assertNull($inspection->plan);
        static::assertStringContainsString('setup failed', TextReport::render($inspection));
        $this->assertCleanedUp($inspection);
    }

    /** @return iterable<string, array{string, int}> */
    public static function commandScenarios(): iterable
    {
        yield 'built-in success' => ['batch-writes', 0];
        yield 'PHP file' => [dirname(__DIR__, 4) . '/examples/mapped-table-sql/scenarios/check-only.php', 0];
        yield 'conflict' => ['optimistic-conflict', 1];
    }

    #[Group('slow')]
    #[DataProvider('commandScenarios')]
    public function test_command_prints_a_redirectable_report_and_preserves_the_exit_status(
        string $scenario,
        int $status,
    ): void {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 4) . '/examples/mapped-table-sql/bin/inspect.php',
            $scenario,
            '--dsn=' . $this->dsn,
            '--no-ansi',
        ]);
        $process->run();
        static::assertSame($status, $process->getExitCode(), $process->getErrorOutput());
        $output = $process->getOutput();
        static::assertStringContainsString('Pending plan', $output);
        static::assertStringContainsString('Parameters:', $output);
        static::assertStringContainsString('[cleanup]', $output);
        static::assertStringContainsString('Result: ' . (0 === $status ? 'OK' : 'FAILED'), $output);
        static::assertStringNotContainsString("\033[", $output);
    }

    private function inspect(string $name): Inspection
    {
        $scenario = require dirname(__DIR__, 4) . '/examples/mapped-table-sql/scenarios/' . $name . '.php';
        return new Inspector($this->connection, $this->trace)->run($scenario);
    }

    /** @return list<SqlTraceEntry> */
    private function scenarioEntries(Inspection $inspection): array
    {
        return array_values(array_filter(
            $inspection->entries,
            static fn(SqlTraceEntry $entry): bool => 'scenario' === $entry->phase,
        ));
    }

    private function assertCleanedUp(Inspection $inspection): void
    {
        static::assertNull($inspection->cleanupError);
        static::assertFalse($this->connection->isTransactionActive());
        static::assertNull($this->connection->fetchOne('SELECT to_regclass(?)::text', [
            'pg_temp.' . $inspection->tableName,
        ]));
    }
}
