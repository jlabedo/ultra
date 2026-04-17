# Event Store

Typed event store API with checkpointed reads and query-scoped optimistic concurrency.

## Core Model

- **Event** – Neutral event value used for both `append()` input and `load()` output on the write side. It contains `eventType`, `payload`, `domainIds`, and optional `recordedAt`.
- **StreamQuery** – Immutable branch list for stream queries. Each branch matches domain identifiers with AND semantics and event types with OR semantics. An empty event type list means all event types. Use `all()` for unfiltered reads, `forStream(...)->ofTypes(...)` for one branch, and `anyOf(...)` for OR-composition. `none()` has no branches and matches no events.
- **Sequence** – Persistence ordering value. Sequence `0` is the beginning position; persisted events use positive sequence numbers.
- **Checkpoint** – Gap-aware sequence position with a stable `gapFloor` plus explicitly observed `gapSeqNos`.
- **LoadResult** – Result of `load()`: visible `Event` objects, the base `StreamQuery`, the next `Checkpoint`, and `appendCondition()` for optimistic writes.
- **AppendCondition** – Optimistic-concurrency expectation tied to one exact `StreamQuery` and the checkpoint that was loaded for it.

## EventStore Interface

```php
interface EventStore
{
    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult;

    public function append(array $events, AppendCondition ...$appendConditions): void;
}
```

`from` is the last checkpoint already seen. `to` is an optional replay or upper bound checkpoint.
The checkpoint returned by `load()` is the next cursor for catch-up and paginated reads.

`append()` can validate zero, one, or many append conditions in the same write. That lets a store acquire locks once, validate every relevant stream snapshot, and insert once.
Append conditions cannot use global queries such as `StreamQuery::all()`, because that would require serializing writes behind one global stream lock.

## Checkpoint Semantics

`Checkpoint` is a persistence cursor, not an in-memory overlay token.

- `Checkpoint::beginning()` starts at sequence `0` with no gap entries.
- `Checkpoint::at($sequence)` is a simple upper bound with no gap entries.
- Catch-up reads use `from` to skip everything already seen.
- Replay reads use `to` to reproduce the visible set captured by a previous checkpoint.
- Optimistic writes pass `load()->appendCondition()` to `append()`.
- It should not be patched to account for in-process pending writes.

This matters when using the unit of work wrapper.

## EventStoreUnitOfWork

`EventStoreUnitOfWork` implements both `EventStore` and `Ultra\UnitOfWork\UnitOfWork`.

It gives read-your-own-writes semantics while still keeping optimistic concurrency aligned with the wrapped store:

- `load()` returns persisted events plus buffered pending events that match the query.
- `load()->checkpoint` stays the checkpoint from the wrapped store.
- `flush()` forwards all buffered events in a single inner `append(...)` call.
- Buffered append conditions are passed through unchanged.

So the visible event list can include pending writes, while `Checkpoint` continues to mean “persisted store position”.

## Projection Boundary

`sequence` is intentionally not part of the write-side `Event` model.

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

$courseResult = $store->load($courseQuery);
$pairResult = $store->load($pairQuery);

$courseAppendCondition = $courseResult->appendCondition();
$pairAppendCondition = $pairResult->appendCondition();

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
], $courseAppendCondition, $pairAppendCondition);
```
