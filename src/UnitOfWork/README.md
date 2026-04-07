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
