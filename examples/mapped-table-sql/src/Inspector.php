<?php

declare(strict_types=1);

namespace MappedTableSqlExample;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\Testing\SqlTrace;
use Ultra\UnitOfWork\DoctrineTransactionManager;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;

/** Runs one row-set scenario on a dedicated, already instrumented connection. */
final readonly class Inspector
{
    public function __construct(
        private Connection $connection,
        private SqlTrace $trace,
    ) {}

    public function run(Scenario $scenario): Inspection
    {
        $this->trace->clear();
        $tableName = 'ultra_sql_' . bin2hex(random_bytes(8));
        $quoted = $this->connection->quoteSingleIdentifier($tableName);
        $rows = new MappedRowSet(new PostgresMappedRowSetAdapter($this->connection, $tableName));
        $runner = new TransactionalUnitOfWorkRunner($rows, new DoctrineTransactionManager($this->connection));
        $plan = null;
        $error = null;
        $cleanupError = null;

        try {
            $this->trace->during('setup', function () use ($scenario, $tableName, $quoted): void {
                $this->connection->executeStatement(
                    'CREATE TEMPORARY TABLE ' . $quoted . ' (id TEXT PRIMARY KEY, status TEXT, note TEXT)',
                );
                $scenario->prepare?->__invoke($this->connection, $tableName);
                foreach ($scenario->initialRows as $row) {
                    $this->connection->insert($tableName, $row);
                }
            });

            $this->trace->during('scenario', function () use ($runner, $scenario, $rows, $tableName, &$plan): void {
                $runner->run(function () use ($scenario, $rows, $tableName, &$plan): void {
                    try {
                        ($scenario->work)($rows, $this->connection, $tableName);
                    } finally {
                        // The runner clears after both work and flush failures; retain the snapshot first.
                        $plan = $rows->updatePlan();
                    }
                });
            });
        } catch (\Throwable $exception) {
            $error = $exception;
        } finally {
            try {
                $this->trace->during('cleanup', function () use ($quoted): void {
                    while ($this->connection->isTransactionActive()) {
                        $this->connection->rollBack();
                    }
                    $this->connection->executeStatement('DROP TABLE IF EXISTS pg_temp.' . $quoted);
                });
            } catch (\Throwable $exception) {
                $cleanupError = $exception;
            }
        }

        return new Inspection($tableName, $plan, $this->trace->entries(), $error, $cleanupError);
    }
}
