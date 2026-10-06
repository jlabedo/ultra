# Unit Of Work

Minimal unit-of-work contract for buffering operations and flushing them later inside an external transaction boundary.

## Core Contract

```php
interface UnitOfWork
{
    public function hasPendingChanges(): bool;

    public function flush(): void;

    public function clear(): void;
}
```

`UnitOfWork` implementations do not open transactions themselves.

- `hasPendingChanges()` returns true only when flushing would perform transactional work.
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

`DoctrineTransactionManager` is the optional Doctrine DBAL adapter (requires `doctrine/dbal`):

```php
$transactions = new DoctrineTransactionManager(
    $connection,
    isolationLevel: TransactionIsolationLevel::READ_COMMITTED,
);
```

It owns its transaction, commits and returns the callback result on success, and rolls back
on failure. An already active connection transaction is rejected, rather than joining a
boundary whose commit it cannot control. Nested work should join the unit-of-work runner
before it reaches this adapter. Without an explicit isolation level, the connection's
configuration is used. With one, the adapter temporarily sets it through DBAL and restores
the previous level after commit or rollback. Configure isolation through DBAL as well so its
cached connection configuration remains consistent with the database session.


## UnitOfWorkRunner

`UnitOfWorkRunner` is the user-facing API for application work that should flush on success and clear afterward:

```php
$runner->run(function () use ($mediator, $context): void {
    $mediator->send(new A(), $context);
    $mediator->send(new B(), $context);
});
```

`TransactionalUnitOfWorkRunner` runs the callback first. If the unit of work has pending changes, it then flushes through `TransactionManager` and clears the unit of work after success or failure. If no changes are pending, it skips the transaction and only clears buffered state.

Nested calls join the active runner. A nested exception marks the active run rollback-only before rethrowing; if outer code catches that exception, the outer runner clears the unit of work instead of flushing.

## Ordering and limits

Reads and decisions run before the flush transaction so transactions remain short and buffered work can be ordered together. CompositeUnitOfWork follows caller-provided component order; StateStore orders declared query guards and manual guards. Ordering reduces deadlock risks but does not eliminate conflicts with other writers or locks acquired in a different order. Read [query guards](../stored-state/query-guards.md) when changing those protocols and the [ADR](../adr/0003-defer-transactions-to-ordered-flush.md) for rationale.

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

For service registration, read [Symfony wiring](symfony.md).

## Guarantee suites

The context's runtime and container guarantees live together under `src/UnitOfWork/Tests`.

| Guarantee | Suite and useful variants |
| --- | --- |
| Components retain caller order for flush and clear; pending work is aggregated; work finishes before the flush transaction and always clears | [FlushCoordinationTest](../../src/UnitOfWork/Tests/FlushCoordinationTest.php): keyed arrays, generators with repeated keys, empty/clean/dirty components, success, work failure and flush failure |
| A transaction manager owns commit/rollback, preserves callback results and failures, rejects external transactions and restores isolation | [TransactionOwnershipTest](../../src/UnitOfWork/Tests/TransactionOwnershipTest.php): real Postgres transactions with connection, overridden and identical isolation; the no-op implementation preserves callback semantics |
| Nested work shares the outer boundary; caught failures or explicit rollback-only prevent flushing; later runs start clean | [NestedWorkTest](../../src/UnitOfWork/Tests/NestedWorkTest.php): success, propagated/caught failures, active and idle rollback-only requests, runner reuse |
| Compiled handlers and hooks share one work boundary and retain caller context | [HandlerWorkBoundaryTest](../../src/UnitOfWork/Tests/HandlerWorkBoundaryTest.php): commands, queries, synchronous events, asynchronous consumers, hook writes and handler/flush failures |
| Container assembly collects participants in priority order, decorates the event store and supplies the runner and default transaction manager | [ContainerCompositionTest](../../src/UnitOfWork/Tests/ContainerCompositionTest.php): autoconfiguration, explicit tags, priority, flush and clear |

[Local support](../../src/UnitOfWork/Tests/Support) shares call recording and container setup without moving assertions out of the suites. Compiled test artifacts use a separate directory per case; Postgres tables are temporary and scoped to each connection. Storage semantics remain owned by EventStore, MappedTable, StateStore and MessageHandling; these suites verify their coordination boundaries and assembly. Follow the shared [test-refactoring rules](../development.md#refactoring-tests) when changing this map.
