# Mapped Table Postgres

PostgreSQL row-mutation unit of work for explicitly buffered table operations.

## Purpose

`Ultra\MappedTable_Postgres\PostgresMappedTable` lets you buffer row-level changes and flush them later inside an external transaction boundary.

It is not an ORM diffing tool. You declare the intended operation directly:

- which row is targeted
- which columns are expected to have specific values
- which columns should be written
- whether the final intent is update, insert, delete, replace, or upsert

## Core API

```php
$table = new PostgresMappedTable($connection, 'users');

$table->withId('user-1')
    ->expectColumnValue('status', 'pending')
    ->setColumn('status', 'active');
```

`withId(...)` returns a fluent `PendingRowUpdate` handle for one row in the table.

## Supported Operations

### Update

```php
$table->withId('row-1')
    ->expectColumnValue('version_a', 'A1')
    ->expectColumnValue('version_b', 'B1')
    ->setColumn('status', 'active')
    ->setColumn('note', 'published');
```

This flushes as a conditional `UPDATE` and throws `Ultra\UnitOfWork\Exception\ConcurrencyException` when the expected values do not match.

### Insert

```php
$table->withId('row-1')
    ->insert()
    ->setColumn('status', 'pending');
```

This flushes as an `INSERT ... ON CONFLICT DO NOTHING` and fails with `ConcurrencyException` if the row already exists.

### Delete

```php
$table->withId('row-1')
    ->expectColumnValue('version_a', 'A1')
    ->delete();
```

This flushes as a conditional `DELETE`.

### Upsert

```php
$table->withId('row-1')
    ->upsert()
    ->setColumn('status', 'pending');
```

This inserts when the row is absent and updates when the row is present.

## Optimistic Concurrency

Expected values are modeled explicitly with:

- `expectColumnValue(string $column, mixed $value)`
- `expectColumnValues(array $expectedValues)`

These expectations are checked in SQL with `IS NOT DISTINCT FROM`, so `null` expectations are supported correctly.

## Merge Semantics

Operations targeting the same row are merged in memory before flush.

Important rules:

- repeated `withId('same-row')` calls act on one buffered row plan
- conflicting expectations for the same column throw immediately
- `insert()` followed by `delete()` becomes a no-op
- `delete()` followed by `insert()` becomes a replace
- `delete()` followed by `upsert()` also becomes a replace
- `setColumn(...)` after `delete()` throws immediately
- `insert()` rejects expected column values, because the row is already expected to be absent

The row plan also remembers the initial expected presence of the row:

- expected present
- expected absent
- unknown

That lets sequences like delete-then-insert preserve the original optimistic baseline instead of forgetting it.

## Flush Semantics

`flush()`:

1. sorts buffered rows deterministically by row id
2. executes the SQL operation implied by each row’s final mode
3. throws `ConcurrencyException` when a conditional update/delete/insert fails

The package assumes the surrounding transaction has already been opened by the caller.

## Unit Of Work Integration

`PostgresMappedTable` implements `Ultra\UnitOfWork\UnitOfWork`:

```php
$connection->beginTransaction();

try {
    $table->flush();
    $connection->commit();
} catch (\Throwable $exception) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $table->clear();

    throw $exception;
}
```

You can also compose it with other unit-of-work instances through `Ultra\UnitOfWork\CompositeUnitOfWork`.

## Scope

This package is intentionally per-table.

It does not try to:

- infer changes from loaded entities
- manage associations
- auto-load rows before mutation
- act as a generic ORM layer

It is a small explicit write planner for one mapped table inside Postgres.
