# Unit Of Work

Minimal unit-of-work contract for buffering operations and flushing them later inside an external transaction boundary.

## Core Contract

```php
interface UnitOfWork
{
    public function flush(): void;

    public function clear(): void;
}
```

This package does not open transactions itself.

- `flush()` assumes the caller has already opened the surrounding transaction, if one is needed.
- `clear()` drops in-memory buffered work after commit or rollback.

## CompositeUnitOfWork

`CompositeUnitOfWork` lets you compose several unit-of-work instances and flush them in a deterministic caller-defined order.

```php
$unitOfWork = new CompositeUnitOfWork([
    $eventStoreUnitOfWork,
    $mappedTableUnitOfWork,
]);
```

## Lifecycle phases

Most unit-of-work participants flush before the surrounding transaction commits. Services can opt into a different phase with attributes:

```php
#[FlushBeforeCommit(priority: 0)]
final class TransactionalOutbox implements UnitOfWork {}

#[FlushAfterCommit(priority: 0)]
final class ExternalBrokerSender implements UnitOfWork {}
```

`UnitOfWorkLifecycle` coordinates both phases without owning the database transaction:

```php
$connection->beginTransaction();

try {
    $handler($message);

    $lifecycle->flushBeforeCommit();
    $connection->commit();
    $lifecycle->flushAfterCommit();
} catch (\Throwable $exception) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $lifecycle->clear();

    throw $exception;
}
```

## Application lifecycle

`ApplicationLifecycle` owns the common application boundary while delegating transaction mechanics:

```php
$result = $applicationLifecycle->run(
    fn () => $mediator->send($command),
);
```

The runner performs:

```php
begin
execute operation
flush before-commit unit-of-work participants
commit
flush after-commit unit-of-work participants
clear all participants
```

If the operation or before-commit flush fails, the active transaction is rolled back and all participants are cleared. If after-commit work fails, the transaction has already committed; the error is rethrown after clearing buffered work.

Nested `run()` calls join the active lifecycle. Only the outermost call begins, commits, flushes, and clears.

## Typical Usage

```php
$connection->beginTransaction();

try {
    $handler($message);

    $unitOfWork->flush();

    $connection->commit();
} catch (\Throwable $exception) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $unitOfWork->clear();

    throw $exception;
}
```

The package stays intentionally small so concrete packages can implement their own buffering, locking, and validation strategies without forcing a bigger shared protocol.
