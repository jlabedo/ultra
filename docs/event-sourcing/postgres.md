# Event Store Postgres

PostgreSQL implementations of `Ultra\EventStore\EventStore`.

## Implementations

- **PostgresEventStoreHStore** – Stores events in one `events` table and evaluates queries directly from the persisted `hstore` domain identifiers.
- **PostgresEventStoreQueryStreams** – Stores events once and also indexes one row per domain identifier in `event_domain_ids` for relational query replay.

Both implementations share the same public contract:

- `load(StreamQuery, ?Checkpoint $from = null, ?Checkpoint $to = null, int $batchSize = 1000): LoadResult`
- `append(array $events, AppendCondition ...$appendConditions): void`

The events table uses a `sequence BIGINT` identity primary key and an explicit `xid XID8` capture column.
Unbounded reads use one SQL statement with `pg_current_snapshot()` to build a gap-aware checkpoint in sequence space.

## Concurrency Model

`append()` is responsible for:

1. Passing the append conditions and appended events to the configured lock strategy.
2. Letting the lock strategy resolve the read-side and write-side advisory-lock namespaces it needs.
3. Acquiring advisory locks in deterministic order.
4. Validating every append condition checkpoint under those locks.
5. Inserting the events once.

That means one append can guard multiple stream snapshots at once.

The lock mechanism is selected through `PostgresEventStoreLockStrategy` and must match the schema DDL:

- `shared-exclusive` is the former strategy: read namespaces take shared advisory locks and write namespaces take exclusive advisory locks.
- `bucket` uses separate read/write buckets per namespace. Same-bucket operations can run together, so unguarded writes to the same namespace can overlap, while guarded read/write conflicts still serialize.

For `bucket`, a namespace used on both sides of an append excludes both existing readers and writers.
One-sided operations on the same side remain compatible. PHP deduplicates and orders numeric lock pairs
before acquisition; all retained locks last until the surrounding transaction ends.

The current strategies support typed and untyped query branches with any number of domain identifiers. Untyped
branches use a wildcard event-type namespace, and writes lock every non-empty domain-identifier subset for both
the concrete event type and the wildcard. Global queries such as `StreamQuery::all()` are read-only; creating an
append condition for one is rejected before a lock strategy is involved.

## Transactions

Both implementations join an existing transaction when one is already active. If no transaction is open, they open and manage their own transaction around the append.

This makes them work naturally with:

- plain direct usage
- external DBAL transaction boundaries
- `Ultra\EventStore\EventStoreUnitOfWork`, whose `flush()` ends in one inner append call

## Choosing an Implementation

Use `PostgresEventStoreHStore` when you want the simpler storage model based on direct event-table queries.

Use `PostgresEventStoreQueryStreams` when you want a normalized domain-identifier index instead of the `hstore` extension.

## Benchmarks

[PostgresEventStoreBench](../../src/EventSourcing/Tests/Benchmark/PostgresEventStoreBench.php) exercises the public
EventStore API against a real database, with both implementations and both lock strategies.
Use [benchmark tooling](../development.md#benchmarks) to run and compare results, and the
[test connection setup](../development.md#tests) to select the database or driver.

| Subject | One timed revolution |
| --- | --- |
| `benchReadPage` | Load the first page of hot/warm subscriptions, course lifecycle, course/student pair or dashboard changes |
| `benchReplay` | Load the complete hot subscription history, following `hasMore` and checkpoints across pages |
| `benchAppend` | Construct and append a batch of one or 100 subscription events, with its own committed transaction |
| `benchGuardedSubscription` | Load the complete course history, derive enrollment state and append one subscription guarded by the final checkpoint |

Each iteration creates and removes its own tables. [CourseDataset](../../src/EventSourcing/Tests/Benchmark/CourseDataset.php)
provides deterministic, skewed course histories. Reads are checked against an in-memory reference; writes are
checked against expected persisted events and enrollment statuses. [BenchmarkTest](../../src/EventSourcing/Tests/BenchmarkTest.php)
protects these workloads. The benchmark's `variants()` provider owns dataset defaults and environment overrides.

For GIN maintenance comparisons, set `ULTRA_BENCH_INDEX_STATE` to `maintained` for `VACUUM (ANALYZE)` after
seeding, or `post-ingest` for `ANALYZE` alone. Preparation runs outside measurement and covers both the events
table and QueryStreams' identifier table. Autovacuum remains governed by server configuration: establish the
pending-list state before attributing a timing difference to GIN cleanup.

Read subjects warm the cache. Write subjects advance through streams round-robin and retain their writes
until the next iteration; a stream is revisited only after a full round. Use more revolutions than streams to
exercise growing guarded histories. Write warmup also adds events to that history. These sequential measurements
cover lock overhead; measuring contention requires concurrent producers.
