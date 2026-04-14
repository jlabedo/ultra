# Event Store Postgres

PostgreSQL implementations of `Ultra\EventStore\EventStore`.

## Implementations

- **PostgresEventStoreHStore** – Stores events in one `events` table and evaluates queries directly from the persisted `hstore` domain identifiers.
- **PostgresEventStoreQueryStreams** – Stores events once and also indexes one row per domain identifier in `event_domain_ids` for relational query replay.

Both implementations share the same public contract:

- `load(StreamQuery): LoadResult`
- `append(array $events, QueryVersion ...$expectedVersions): void`

## Concurrency Model

`append()` is responsible for:

1. Resolving all read-side keys from the expected query versions.
2. Resolving all write-side singleton and pair keys from the events being appended.
3. Acquiring advisory locks in deterministic order.
4. Validating every expected query version under those locks.
5. Inserting the events once.

That means one append can guard multiple stream snapshots at once.

The lock mechanism is selected through `PostgresEventStoreLockStrategy` and must match the schema DDL:

- `shared-exclusive` is the former strategy: read keys take shared advisory locks and write keys take exclusive advisory locks.
- `bucket` uses separate read/write buckets per key. Same-bucket operations can run together, so unguarded writes to the same key can overlap, while guarded read/write conflicts still serialize.

The Postgres benchmark script accepts `--lock-strategy=shared-exclusive` or `--lock-strategy=bucket`.

## Transactions

Both implementations join an existing transaction when one is already active. If no transaction is open, they open and manage their own transaction around the append.

This makes them work naturally with:

- plain direct usage
- external DBAL transaction boundaries
- `Ultra\EventStore\EventStoreUnitOfWork`, whose `flush()` ends in one inner append call

## Choosing an Implementation

Use `PostgresEventStoreHStore` when you want the simpler storage model based on direct event-table queries.

Use `PostgresEventStoreQueryStreams` when you want a normalized domain-identifier index instead of the `hstore` extension.
