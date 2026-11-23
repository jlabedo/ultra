<?php

declare(strict_types=1);

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\MappedTable\Storage\PostgresRowSetSql;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\SqlTrace;
use Ultra\Testing\SqlTraceMiddleware;
use Ultra\UnitOfWork\DbalTransactionManager;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;

require dirname(__DIR__) . '/bootstrap.php';

const BENCHMARK_TABLE = 'mapped_table_benchmark_items';

function prepare(Connection $connection, string $workload): void
{
    $table = 'pg_temp.' . BENCHMARK_TABLE;
    $connection->executeStatement("TRUNCATE {$table}");
    if (in_array($workload, ['unchanged-250', 'unchanged-batch-250'], true)) {
        $connection->executeStatement(
            "INSERT INTO {$table} SELECT 'a', id, 'draft' FROM generate_series(1, 250) AS id",
        );
    }
    if ('reconcile-200' === $workload) {
        $connection->executeStatement("INSERT INTO {$table} SELECT owner, id, 'draft'
             FROM (VALUES ('a'), ('b')) AS owners(owner) CROSS JOIN generate_series(1, 100) AS id");
    }
}

function execute_workload(
    string $workload,
    Connection $connection,
    MappedRowSet $rows,
    MappedTableUnitOfWork $unit,
    TransactionalUnitOfWorkRunner $runner,
): void {
    $runner->run(static function () use ($workload, $connection, $rows, $unit): void {
        if ('guarded-100' === $workload) {
            $guard = $unit->queryGuard('mapped-table-benchmark');
            $table = BENCHMARK_TABLE;
            $count = $guard->observe(
                'a',
                static fn(): int => (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table} WHERE owner = ?", ['a']),
            );
            if (0 !== $count) {
                throw new RuntimeException('Expected an empty guarded collection.');
            }
        }
        switch ($workload) {
            case 'guarded-100':
            case 'insert-1000':
                $size = 'guarded-100' === $workload ? 100 : 1000;
                for ($id = 1; $id <= $size; ++$id) {
                    $rows->withKey(['owner' => 'a', 'id' => $id])->insert()->setColumn('status', 'paid');
                }
                break;
            case 'unchanged-250':
                for ($id = 1; $id <= 250; ++$id) {
                    $row = $rows->withKey(['owner' => 'a', 'id' => $id]);
                    if (['status' => 'draft'] !== $row->observe(['status'])) {
                        throw new RuntimeException('Expected a draft item.');
                    }
                    $row->setColumn('status', 'draft');
                }
                break;
            case 'unchanged-batch-250':
                $keys = array_map(static fn(int $id): array => ['owner' => 'a', 'id' => $id], range(1, 250));
                foreach ($rows->observeMany($keys, ['status']) as $index => $row) {
                    if (['status' => 'draft'] !== $row) {
                        throw new RuntimeException('Expected a draft item.');
                    }
                    $rows->withKey($keys[$index])->setColumn('status', 'draft');
                }
                break;
            case 'reconcile-200':
                $keys = $rows->observeKeysForOwner(['owner' => 'a']);
                if (100 !== count($keys)) {
                    throw new RuntimeException('Expected 100 observed keys.');
                }
                $desired = [];
                for ($id = 1; $id <= 50; ++$id) {
                    $desired[] = new MappedRow(['owner' => 'a', 'id' => $id], ['status' => 'paid']);
                }
                $rows->reconcileForOwner(['owner' => 'a'], $desired);
                $rows->reconcileForOwner(['owner' => 'b'], []);
                break;
            default:
                throw new LogicException('Unknown benchmark workload.');
        }
    });
}

function verify(Connection $connection, string $workload): void
{
    $table = 'pg_temp.' . BENCHMARK_TABLE;
    $actual = $connection->fetchAssociative("SELECT COUNT(*) AS total,
           COUNT(*) FILTER (WHERE owner = 'a' AND status = 'paid') AS paid,
           COUNT(*) FILTER (WHERE owner = 'a' AND status = 'draft') AS draft,
           MIN(id) AS first, MAX(id) AS last
         FROM {$table}");
    $expected = match ($workload) {
        'insert-1000' => [1000, 1000, 0, 1, 1000],
        'guarded-100' => [100, 100, 0, 1, 100],
        'unchanged-250', 'unchanged-batch-250' => [250, 0, 250, 1, 250],
        'reconcile-200' => [50, 50, 0, 1, 50],
    };
    if (false === $actual || $expected !== array_map(intval(...), array_values($actual))) {
        throw new RuntimeException("Unexpected stored rows for {$workload}.");
    }
}

/** @return array<string, mixed> */
function benchmark(Connection $connection, string $workload, int $warmups, int $samples, ?SqlTrace $trace): array
{
    $table = BENCHMARK_TABLE;
    $connection->executeStatement(
        "CREATE TEMP TABLE IF NOT EXISTS {$table} (owner TEXT, id INTEGER, status TEXT NOT NULL, PRIMARY KEY (owner, id))",
    );
    $definition = new class implements MappedRowSetDefinition {
        public function mapping(): MappedRowSetMapping
        {
            return new MappedRowSetMapping(
                BENCHMARK_TABLE,
                [
                    'owner' => new MappedColumn('string'),
                    'id' => new MappedColumn('int'),
                    'status' => new MappedColumn('string'),
                ],
                ['owner', 'id'],
                ownerColumns: ['owner'],
            );
        }
    };
    $mapping = $definition->mapping();
    $adapter = new PostgresMappedTableAdapter($connection);
    $rows = $adapter->createCompiled($mapping, PostgresRowSetSql::compile($mapping, []));
    $guarded = 'guarded-100' === $workload;
    $unit = new MappedTableUnitOfWork(
        [$definition::class => $rows],
        $adapter,
        queryGuards: $guarded
            ? [new QueryGuardDefinition('mapped-table-benchmark', [
                new QueryGuardDependency($definition::class, 'owner'),
            ])] : [],
    );
    $runner = new TransactionalUnitOfWorkRunner($unit, new DbalTransactionManager($connection));
    $milliseconds = [];
    $peakBytes = [];
    $statements = [];
    $largestWriteParameterCount = 0;
    for ($iteration = 0; $iteration < ($warmups + $samples); ++$iteration) {
        prepare($connection, $workload);
        $trace?->clear();
        memory_reset_peak_usage();
        $memoryBefore = memory_get_usage();
        $started = hrtime(true);
        execute_workload($workload, $connection, $rows, $unit, $runner);
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        if ($iteration >= $warmups) {
            $milliseconds[] = $elapsed;
            $peakBytes[] = memory_get_peak_usage() - $memoryBefore;
        }
        foreach ($trace?->entries() ?? [] as $entry) {
            if (null === $entry->sql) {
                continue;
            }
            $verb = strtoupper(strtok(ltrim($entry->sql), " \n\r\t"));
            $statements[$verb] = ($statements[$verb] ?? 0) + 1;
            if (in_array($verb, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                $largestWriteParameterCount = max($largestWriteParameterCount, count($entry->parameters));
            }
        }
        verify($connection, $workload);
    }
    if (null !== $trace) {
        ksort($statements);

        return ['sql' => $statements, 'largest_write_parameter_count' => $largestWriteParameterCount];
    }
    sort($milliseconds);
    sort($peakBytes);

    return [
        'median_ms' => round($milliseconds[(int) ceil($samples * 0.5) - 1], 3),
        'p95_ms' => round($milliseconds[(int) ceil($samples * 0.95) - 1], 3),
        'median_peak_php_bytes' => $peakBytes[(int) ceil($samples * 0.5) - 1],
    ];
}

$dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
if (false === $dsn || '' === $dsn) {
    $dsn = 'pdo-pgsql://ultra:ultra@127.0.0.1:55432/ultra_test';
}
$connection = PostgresConnectionSupport::connection($dsn);
$trace = new SqlTrace();
$configuration = new Configuration();
$configuration->setMiddlewares([new SqlTraceMiddleware($trace)]);
$observed = PostgresConnectionSupport::connection($dsn, $configuration);
$warmups = 5;
$samples = 30;

try {
    $result = [
        'php' => PHP_VERSION,
        'php_host' => PHP_OS_FAMILY . ' ' . php_uname('m'),
        'opcache_cli' => ini_get('opcache.enable_cli'),
        'dbal' => Composer\InstalledVersions::getPrettyVersion('doctrine/dbal'),
        'driver' => $connection->getDriver()::class,
        'postgres' => $connection->fetchOne('SHOW server_version'),
        'warmups' => $warmups,
        'samples' => $samples,
        'workloads' => [],
    ];
    foreach (['insert-1000', 'unchanged-250', 'unchanged-batch-250', 'reconcile-200', 'guarded-100'] as $workload) {
        $result['workloads'][$workload] =
            benchmark($connection, $workload, $warmups, $samples, null) + benchmark($observed, $workload, 0, 1, $trace);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    $observed->close();
    $connection->close();
}
