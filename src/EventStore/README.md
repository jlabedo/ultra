# Event Store

Typed event store API with query-scoped optimistic concurrency.

## Core Model

- **Event** – Neutral event value used for both `append()` input and `load()` output on the write side. It contains `eventType`, `payload`, `domainIds`, and optional `recordedAt`.
- **StreamQuery** – Immutable branch list for stream queries. Each branch matches domain identifiers with AND semantics and event types with OR semantics. An empty event type list means all event types. Use `forStream(...)->ofTypes(...)` for one branch and `anyOf(...)` for OR-composition.
- **LoadResult** – Result of `load()`: a list of visible `Event` objects plus a `VersionedQuery`.
- **VersionedQuery** – Optimistic-concurrency token tied to one exact `StreamQuery`. It carries an opaque `VersionToken` owned by the event store implementation.

## EventStore Interface

```php
interface EventStore
{
    public function load(StreamQuery|VersionedQuery $query): LoadResult;

    public function append(array $events, VersionedQuery ...$expectedQueries): void;
}
```

Passing a `VersionedQuery` to `load()` reloads its embedded `StreamQuery` and returns a fresh `VersionedQuery`.
The old token is not validated by `load()`.

`append()` can validate zero, one, or many versioned queries in the same write. That lets a store acquire locks once, validate every relevant stream snapshot, and insert once.

## VersionedQuery Semantics

`VersionedQuery` is a persistence token, not an in-memory overlay token.

- It represents the snapshot returned by the underlying store for a specific query.
- Its `VersionToken` is opaque. Callers pass it back unchanged instead of inspecting it.
- It can be passed to `load()` as a reusable handle for the same `StreamQuery`.
- It is meant for optimistic locking against the transactional database.
- It should not be patched to account for in-process pending writes.

This matters when using the unit of work wrapper.

## EventStoreUnitOfWork

`EventStoreUnitOfWork` implements both `EventStore` and `Ultra\UnitOfWork\UnitOfWork`.

It gives read-your-own-writes semantics while still keeping optimistic concurrency aligned with the wrapped store:

- `load()` returns persisted events plus buffered pending events that match the query.
- `load()->versionedQuery` stays the `VersionedQuery` from the wrapped store.
- `flush()` forwards all buffered events in a single inner `append(...)` call.
- Buffered expected versioned queries are passed through unchanged.

So the visible event list can include pending writes, while `VersionedQuery` continues to mean “persisted snapshot token”.

## Projection Boundary

`eventId` is intentionally not part of the write-side `Event` model.

If you need persisted ordering/checkpoint identifiers for projections, use `Ultra\Projection\EventFeed` and `Ultra\Projection\StoredEvent`. That is the read-model/projection boundary where persistence identity belongs.

## Example

```php
$courseQuery = StreamQuery::forStream(
    new DomainIdentifier('courseId', 'course-123'),
)->ofTypes('student.subscribed');

$pairQuery = StreamQuery::forStream(
    new DomainIdentifier('courseId', 'course-123'),
    new DomainIdentifier('studentId', 'student-1'),
)->ofTypes('student.subscribed');

$courseVersionedQuery = $store->load($courseQuery)->versionedQuery;
$pairVersionedQuery = $store->load($pairQuery)->versionedQuery;

$store->append([
    new Event(
        eventType: 'student.subscribed',
        payload: ['slot' => 1],
        domainIds: [
            'facultyId' => 'faculty-1',
            'courseId' => 'course-123',
            'studentId' => 'student-1',
        ],
    ),
], $courseVersionedQuery, $pairVersionedQuery);
```
