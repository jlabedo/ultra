# Event Store Postgres

PostgreSQL implementations of `Ultra\EventStore\EventStore`.

## Implementations

- **PostgresEventStore** – Stores events in one `events` table and evaluates canonical queries directly from the persisted event rows.
- **PostgresEventStoreQueryStreams** – Stores events once and also materializes query-stream membership in `event_query_streams` for faster query replay on supported canonical streams.

Both implementations share the same public contract:

- `load(StreamQuery): LoadResult`
- `append(array $events, QueryVersion ...$expectedVersions): void`

## Concurrency Model

`append()` is responsible for:

1. Resolving all read-side canonical keys from the expected query versions.
2. Resolving all write-side keys from the events being appended.
3. Acquiring advisory locks in deterministic order.
4. Validating every expected query version under those locks.
5. Inserting the events once.

That means one append can guard multiple stream snapshots at once.

## Transactions

Both implementations join an existing transaction when one is already active. If no transaction is open, they open and manage their own transaction around the append.

This makes them work naturally with:

- plain direct usage
- external DBAL transaction boundaries
- `Ultra\EventStore\EventStoreUnitOfWork`, whose `flush()` ends in one inner append call

## Choosing an Implementation

Use `PostgresEventStore` when you want the simpler storage model based on direct event-table queries.

Use `PostgresEventStoreQueryStreams` when you want query-stream membership materialized explicitly and are comfortable maintaining the extra table.
