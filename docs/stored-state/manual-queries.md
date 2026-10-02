# Manual queries and deferred guards

Read when a set-based invariant needs explicit query expectations or locks without compiled dependency declarations. For named dependency guards, use the [query guard contract](query-guards.md).


For rules expressed as SQL queries, record the guards and the observed results during the decision:

```php
$unitOfWorkRunner->run(function () use ($stateStore, $repository, $company, $room, $slot, $booking): void {
    $stateStore->expect($company);
    $stateStore->expect($room);
    $stateStore->lock($company);
    $stateStore->lock($room);

    $used = $stateStore->observeQuery(
        fn (): int => $repository->usedMinutes($company, $slot->month()),
        name: 'monthly usage',
    );
    // Validate the quota and the other business rules, then prepare the write.
    $stateStore->expectMissing($booking::class, $booking->id);
    $stateStore->save($booking);
});
```

`observeQuery($query, ?string $name = null)` executes the callback immediately, registers its result as the expected
value, and returns it. `expectQuery($query, $expected, ?string $name = null)` registers an expectation without
executing the callback. Expected values must be scalars, null or arrays of these values. Comparison uses `===`:
cast database numeric values, normalize timestamps, and sort unordered query results in the callback. A mismatch
throws `Ultra\UnitOfWork\Exception\ConcurrencyException`; the optional name identifies the failed expectation.

`lock($state)` records a guard in `StateTableRegistry` for the state's physical table and mapped key, including
composite keys and missing rows. Registering a guard takes no database lock. It neither replaces `expect()` nor protects against writers that
do not request the same guard. The developer chooses the guards and must use them in every action affecting the
invariant: booking, cancellation, reschedule and bulk closure, for example.

The `StateTableRegistry` flush has three phases, inside **one transaction**:

1. Derive any compiled query guards, acquire them in name/value order, then acquire requested manual advisory locks
   in physical table/key order.
2. Replay all query expectations before applying any row-set plan.
3. Check the optimistic row expectations and apply the existing write plans.

Configure a dedicated `GuardLockAdapter` service when using manual or compiled query guards. For Postgres:

```yaml
Ultra\StateStore\GuardLockAdapter:
    class: Ultra\StateStore\PostgresGuardLockAdapter
    arguments:
        $connection: '@doctrine.dbal.default_connection'
```

Use `InMemoryGuardLockAdapter` with the same `InMemoryRowDatabase` as the mapped adapters for in-memory work.
`MappedRowSet` contains only row plans and optimistic expectations; cooperative guards are owned by the registry
and acquired through the dedicated adapter. Guard-only work does not need to create a mapped row set.

With `TransactionalUnitOfWorkRunner`, application work runs before the transaction is opened. Configure its
`TransactionManager` for the same connection used by the mapped adapters, guard adapter and query callbacks. The runner commits
or rolls back the entire flush and clears the queues on success or failure. Calling `StateTableRegistry::flush()`
directly requires the caller to provide that transaction and call `clear()` when the unit of work ends. Query-only
and guard-only work also counts as pending. This mechanism uses no `SERIALIZABLE` isolation and does not prelock
every row that will be written. Ordered manual guards do not remove every possible database deadlock, particularly
when other writers or callbacks acquire additional locks in a different order.

Callbacks must be read-only, repeatable, and use the flush connection. Capture stable parameters; they must read
committed database data, not `StateStore::load()`'s buffered view. Query results are compared **before this unit of
work's writes**. Account for pending changes separately when deciding, without adding them to the SQL expectation.
The unguarded API has no dependency metadata, so it cannot detect when these results ignore affected local writes.
