# Inspecting MappedTable SQL

Standalone PHP scenarios for inspecting a `MappedRowSet` plan and the SQL its PostgreSQL adapter actually
executes. The runner uses a real PostgreSQL database. The collector observes DBAL driver calls; it does not
simulate responses or generate a separate SQL preview.

## Run a scenario

From the repository root, install dependencies with `composer install` and start the development database
with `docker compose up -d`. Then run:

```bash
export ULTRA_TEST_POSTGRES_DSN='pdo-pgsql://ultra:ultra@127.0.0.1:55432/ultra_test'
php examples/mapped-table-sql/bin/inspect.php batch-writes
php examples/mapped-table-sql/bin/inspect.php check-only
php examples/mapped-table-sql/bin/inspect.php optimistic-conflict
```

Alternatively, install this example independently with `composer install` in this directory, then run
`php bin/inspect.php`. Pass `--dsn` to override the environment variable. An explicit `pgsql://` URL selects
the native driver; `pdo-pgsql://` selects PDO. The collector works with both.

| Scenario | What to look for |
| --- | --- |
| [batch-writes](scenarios/batch-writes.php) | Repeated changes to `a` merge in the plan; `a` and `b` share an INSERT, while `c` uses different columns and starts another batch. |
| [check-only](scenarios/check-only.php) | A read precedes BEGIN. Unchanged values and presence expectations cause checks and locks, with no row writes during the scenario. |
| [optimistic-conflict](scenarios/optimistic-conflict.php) | The plan contains three updates, but the second fails its expectation. The trace ends with ROLLBACK; the third update never executes. This demonstration exits with status 1. |

The report prints the immutable plan captured after application work, before flush and clear. It lists row
keys, resolved modes, expected presence and columns, writes, expected membership, ignored keys and retained
membership. A failure during application work retains the plan prepared before that failure. A setup failure
has no plan.

The driver trace stays chronological and labels `setup`, `scenario` and `cleanup`. Each SQL entry keeps its
placeholders, bound parameter values and driver types. Transaction calls and SQL failures are included. The
scenario summary excludes setup and cleanup; its SQL execution count excludes transaction calls and failed
preparation. An optimistic conflict can follow a successful SQL call with zero affected rows: the overall
result then reports the adapter's `ConcurrencyException` separately from driver errors.

The output is plain text and can be redirected:

```bash
php examples/mapped-table-sql/bin/inspect.php check-only --no-ansi > /tmp/check-only-sql.txt
```

Exit status is 0 on success and 1 on scenario or cleanup failure. SQL durations measure the named driver call
using a monotonic clock. They exclude connection setup, successful statement preparation, fetching and PHP
hydration, and include network time and lock waits. Failed preparation is shown as `PREPARE FAILED` when
the driver detects an error at that stage. These observations are diagnostic measurements, not a benchmark
or an explanation of the PostgreSQL execution plan.

## Write a scenario

Create a PHP file returning a [Scenario](src/Scenario.php). It uses the usual `MappedRowSet` methods:

```php
<?php

use MappedTableSqlExample\Scenario;
use Ultra\MappedTable\MappedRowSet;

return new Scenario(
    static function (MappedRowSet $rows): void {
        $row = $rows->row('order-1', ['status']);
        if (false === $row) {
            throw new RuntimeException('Missing initial row.');
        }
        $rows->withKey('order-1')
            ->expectColumnValue('status', $row['status'])
            ->setColumn('status', 'paid');
    },
    initialRows: [['id' => 'order-1', 'status' => 'pending']],
);
```

Pass the file path as the argument: `php examples/mapped-table-sql/bin/inspect.php /path/to/scenario.php`.
The callback also receives the DBAL connection and generated table name as optional second and third
arguments. A `prepare` callback receives the connection and table name before initial rows are inserted,
for additional setup such as adding a column. Scenario files execute as ordinary PHP.

The example creates a fresh temporary table with `id TEXT PRIMARY KEY`, `status TEXT` and `note TEXT` on a
dedicated connection. Reads and decisions run before the flush transaction; success commits and failure
rolls back through `TransactionalUnitOfWorkRunner`. Cleanup drops the temporary table and closes the
connection. The example addresses one unscoped row set with the default `id` key. For different keys, scopes
or column codecs, reuse the collector below in a PHP script assembling the corresponding adapter.

## Reuse the collector

`ultra/testing-postgres` supplies the storage-independent [SqlTrace](../../src/Testing/Postgres/SqlTrace.php)
and [SqlTraceMiddleware](../../src/Testing/Postgres/SqlTraceMiddleware.php). Install the middleware before
creating a connection:

```php
use Doctrine\DBAL\Configuration;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\SqlTrace;
use Ultra\Testing\SqlTraceMiddleware;

$trace = new SqlTrace();
$configuration = new Configuration();
$configuration->setMiddlewares([new SqlTraceMiddleware($trace)]);
$connection = PostgresConnectionSupport::connection($dsn, $configuration);

$trace->during('scenario', function () use ($runner): void {
    $runner->run(static function (): void {
        // Read and buffer application work here.
    });
});
$entries = $trace->entries();
```

When an application already uses middlewares, append the trace middleware to its existing configuration.
Adding a middleware after connection creation does not instrument that connection. `during()` restores
the previous phase even when work throws. `entries()` returns the collected observations, and `clear()`
starts a fresh collection. The collector neither consumes results nor changes returned values or exceptions.
Parameter values in the text report use JSON representation; binary or resource values may require a custom
renderer. Callers own their transaction boundaries and connection cleanup.

## Verification

From the repository root:

```bash
bin/phpunit src/Testing/Tests/SqlTraceTest.php src/StoredState/Tests/MappedTable/SqlInspectionTest.php
bin/phpunit --group=slow src/StoredState/Tests/MappedTable/SqlInspectionTest.php
```

The [SQL trace suite](../../src/Testing/Tests/SqlTraceTest.php) exercises real results, rebound parameters,
transaction calls and driver failures with PDO and native PostgreSQL. The
[inspection suite](../../src/StoredState/Tests/MappedTable/SqlInspectionTest.php) verifies snapshots,
pre-transaction reads, check-only SQL, partial traces on failure, cleanup, reuse and CLI exit statuses.
Storage semantics remain owned by the [MappedTable guarantees](../../docs/stored-state/mapped-table.md#test-ownership-and-maintenance).
