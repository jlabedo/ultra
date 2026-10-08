<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\SqlTrace;
use Ultra\Testing\SqlTraceEntry;
use Ultra\Testing\SqlTraceMiddleware;

/** @internal */
#[CoversClass(SqlTrace::class)]
#[CoversClass(SqlTraceEntry::class)]
#[CoversClass(SqlTraceMiddleware::class)]
#[CoversClass(PostgresConnectionSupport::class)]
final class SqlTraceTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        yield 'PDO' => ['pdo-pgsql'];
        yield 'native' => ['pgsql'];
    }

    #[DataProvider('drivers')]
    public function test_observation_preserves_results_row_counts_and_rebound_parameters(string $driver): void
    {
        $trace = new SqlTrace();
        $connection = $this->connection($trace, $driver);
        try {
            $connection->executeStatement('CREATE TEMPORARY TABLE trace_values (value INTEGER PRIMARY KEY)');
            $trace->clear();
            $trace->during('writes', static function () use ($connection): void {
                $connection->beginTransaction();
                static::assertSame(1, $connection->executeStatement('INSERT INTO trace_values VALUES (3)'));
                $statement = $connection->prepare('INSERT INTO trace_values VALUES (?)');
                foreach ([4, 5] as $value) {
                    $statement->bindValue(1, $value, ParameterType::INTEGER);
                    static::assertSame(1, $statement->executeStatement());
                }
                $connection->commit();
            });
            $values = $connection->fetchFirstColumn('SELECT value FROM trace_values ORDER BY value');
            static::assertSame([3, 4, 5], array_map(intval(...), $values));

            $entries = $trace->entries();
            static::assertSame(
                ['begin', 'exec', 'execute', 'execute', 'commit', 'query'],
                array_column($entries, 'operation'),
            );
            static::assertSame(
                ['writes', 'writes', 'writes', 'writes', 'writes', 'scenario'],
                array_column($entries, 'phase'),
            );
            static::assertSame([1 => 4], $entries[2]->parameters);
            static::assertSame([1 => 5], $entries[3]->parameters);
            static::assertSame([1 => ParameterType::INTEGER], $entries[3]->types);
            foreach ($entries as $entry) {
                static::assertNull($entry->errorClass);
                static::assertGreaterThanOrEqual(0, $entry->durationMilliseconds);
            }
        } finally {
            $connection->close();
        }
    }

    #[DataProvider('drivers')]
    public function test_failed_sql_keeps_its_trace_and_rolls_back_without_changing_the_exception(string $driver): void
    {
        $trace = new SqlTrace();
        $connection = $this->connection($trace, $driver);
        $sql = 'INSERT INTO trace_values VALUES (?)';
        try {
            $connection->executeStatement('CREATE TEMPORARY TABLE trace_values (value INTEGER PRIMARY KEY)');
            $trace->clear();
            $trace->during('outer', static function () use ($connection, $trace, $sql): void {
                $connection->beginTransaction();
                $connection->executeStatement($sql, [1]);
                try {
                    $trace->during('conflict', static fn(): int|string => $connection->executeStatement($sql, [1]));
                    static::fail('Expected the database constraint to reject the duplicate.');
                } catch (Exception\UniqueConstraintViolationException $exception) {
                    static::assertNotEmpty($exception->getMessage());
                }
                $connection->rollBack();
            });
            static::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM trace_values'));
            $entries = $trace->entries();
            static::assertSame(
                ['begin', 'execute', 'execute', 'rollback', 'query'],
                array_column($entries, 'operation'),
            );
            static::assertSame('conflict', $entries[2]->phase);
            static::assertSame('outer', $entries[3]->phase);
            static::assertSame('scenario', $entries[4]->phase);
            static::assertSame($sql, $entries[2]->sql);
            static::assertSame([1 => 1], $entries[2]->parameters);
            static::assertNotNull($entries[2]->errorClass);
            static::assertNotEmpty($entries[2]->errorMessage);
        } finally {
            $connection->close();
        }
    }

    #[DataProvider('drivers')]
    public function test_preparation_or_execution_errors_retain_the_failing_sql(string $driver): void
    {
        $trace = new SqlTrace();
        $connection = $this->connection($trace, $driver);
        $sql = 'SELECT ultra_missing_trace_function(?)';
        try {
            try {
                $connection->fetchOne($sql, ['value']);
                static::fail('Expected an undefined function error.');
            } catch (Exception\DriverException) {
                // PDO and native drivers can detect this at different stages.
            }
            static::assertCount(1, $trace->entries());
            $entry = $trace->entries()[0];
            static::assertSame($sql, $entry->sql);
            static::assertContains($entry->operation, ['prepare', 'execute']);
            static::assertNotNull($entry->errorClass);
        } finally {
            $connection->close();
        }
    }

    private function connection(SqlTrace $trace, string $driver): Connection
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            static::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to verify real SQL tracing.');
        }
        if (!extension_loaded('pgsql' === $driver ? 'pgsql' : 'pdo_pgsql')) {
            static::markTestSkipped('The selected PostgreSQL driver extension is unavailable.');
        }
        $configuration = new Configuration();
        $configuration->setMiddlewares([new SqlTraceMiddleware($trace)]);
        return PostgresConnectionSupport::connection(
            (string) preg_replace('/^[^:]+:/', $driver . ':', $dsn),
            $configuration,
        );
    }
}
