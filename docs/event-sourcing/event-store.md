# Event Store

Typed event store API with checkpointed reads and query-scoped optimistic concurrency.

A stream is an ordered, filtered sequence of events. Current filters select
events by domain identifiers and event names. An event can match multiple streams;
a stream does not require exclusive ownership by a domain entity.

## Core Model

- **Event** – Neutral event value used for both `append()` input and `load()` output on the write side. It contains `eventType`, `payload`, `domainIds`, and optional `recordedAt`.
- **StreamQuery** – Immutable branch list for stream queries. Each branch matches domain identifiers with AND semantics and event types with OR semantics. An empty event type list means all event types. Use `all()` for unfiltered reads, `forStream(...)->ofTypes(...)` for one branch, and `anyOf(...)` for OR-composition. `none()` has no branches and matches no events.
- **Sequence** – Persistence ordering value. Sequence `0` is the beginning position; persisted events use positive sequence numbers.
- **Checkpoint** – Gap-aware sequence position with a stable `gapFloor` plus explicitly observed `gapSeqNos`.
- **LoadResult** – Result of `load()`: visible `Event` objects, the base `StreamQuery`, the next `Checkpoint`, `hasMore` for pagination, and `appendCondition()` for optimistic writes.
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
`hasMore` is true when another matching persisted event is visible beyond the returned page, within `to` when supplied.
Stores inspect one extra event to determine this flag; that event is excluded from the returned list and checkpoint.
Use `hasMore` to continue pagination, including when the last page is exactly full.

`batchSize` limits persisted events. With `EventStoreUnitOfWork`, the final catch-up page also includes all matching
pending events and can exceed that size.

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

- Pages with `hasMore = true` contain persisted events only.
- The final catch-up page (`hasMore = false`) appends all matching buffered pending events in append order,
  even when the visible event count then exceeds `batchSize`. This also applies when the persisted stream is empty.
- Bounded replay (`to`) excludes pending events on every page.
- `load()->checkpoint` stays the checkpoint from the wrapped store.
- `flush()` forwards all buffered events in a single inner `append(...)` call.
- Buffered append conditions are passed through unchanged.

So the visible event list can include pending writes, while `Checkpoint` continues to mean “persisted store position”.

## Projection Boundary

`sequence` is intentionally not part of the write-side `Event` model.

The projection runtime consumes `Ultra\EventStore\Event` through the same EventStore API. Projection progress is tracked by segment checkpoints rather than a separate event DTO carrying a sequence number.

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
