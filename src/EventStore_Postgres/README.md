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

1. Passing the expected query versions and appended events to the configured lock strategy.
2. Letting the lock strategy resolve the read-side and write-side advisory-lock namespaces it needs.
3. Acquiring advisory locks in deterministic order.
4. Validating every expected query version under those locks.
5. Inserting the events once.

That means one append can guard multiple stream snapshots at once.

The lock mechanism is selected through `PostgresEventStoreLockStrategy` and must match the schema DDL:

- `shared-exclusive` is the former strategy: read namespaces take shared advisory locks and write namespaces take exclusive advisory locks.
- `bucket` uses separate read/write buckets per namespace. Same-bucket operations can run together, so unguarded writes to the same namespace can overlap, while guarded read/write conflicts still serialize.

The current strategies support typed and untyped query branches with any number of domain identifiers. Untyped
branches use a wildcard event-type namespace, and writes lock every non-empty domain-identifier subset for both
the concrete event type and the wildcard.

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
