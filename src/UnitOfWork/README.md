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

`UnitOfWork` implementations do not open transactions themselves.

- `flush()` assumes the caller has already opened the surrounding transaction, if one is needed.
- `clear()` drops in-memory buffered work after commit or rollback.

## Transaction Manager

`TransactionManager` is the minimal abstraction for code that needs to run a unit-of-work flush inside a transaction boundary:

```php
interface TransactionManager
{
    public function transactional(callable $callback): mixed;
}
```

Implementations commit when the callback returns and roll back when it throws. `NoTransactionManager` is provided for packages that do not need a real transaction boundary.

## UnitOfWorkRunner

`UnitOfWorkRunner` is the user-facing API for application work that should flush on success and clear afterward:

```php
$runner->run(function () use ($mediator, $context): void {
    $mediator->send(new A(), $context);
    $mediator->send(new B(), $context);
});
```

`TransactionalUnitOfWorkRunner` runs the callback first, flushes the unit of work through `TransactionManager`, and clears the unit of work after success or failure.

## CompositeUnitOfWork

`CompositeUnitOfWork` lets you compose several unit-of-work instances and flush them in a deterministic caller-defined order.

```php
$unitOfWork = new CompositeUnitOfWork([
    $eventStoreUnitOfWork,
    $mappedTableUnitOfWork,
]);
```

## Priority

When using the Symfony bundle, autoconfigured unit-of-work services are collected with Symfony's tagged-iterator support. Use Symfony's `AsTaggedItem` attribute to tune flush order:

```php
#[AsTaggedItem(priority: -100)]
final class TransactionalOutboxSender implements UnitOfWork {}
```

## Low-Level Usage

```php
$handler($message);

$connection->beginTransaction();

try {
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
