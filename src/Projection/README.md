# Projection

The `Projection` package defines how read models are described, replayed, and woken up from durable signals.

It is intentionally split into two layers:

- definition/runtime: what a projection is and how it applies events
- signal integration: how appended events produce projection wake-up hints

Postgres-specific persistence lives in `src/Projection_Postgres`. This package keeps the runtime algorithm storage-agnostic and ships in-memory implementations for tests and local wiring.

## Main Concepts

### `ProjectionDefinition`

[ProjectionDefinition.php](ProjectionDefinition.php) is the static description of a projection class.

It is built from attributes and reflection and contains:

- `projectionId`
- `partitionDimension`
- initializer method, if any
- resetter method, if any
- flush method, if any
- handler metadata per event type

It also knows how to:

- list handled event types
- check whether an event is handled
- compute the event bucket from the partition dimension
- build the event-store query for a whole projection or one segment

### `ProjectionRuntime`

[ProjectionRuntime.php](ProjectionRuntime.php) binds a concrete projection object to its definition and an `UltraNormalizer`.

It is the execution-facing object used by workers:

- `initialize()` runs the `#[ProjectionInitializer]` method once when the projection is first created
- `reset()` runs the `#[ProjectionResetter]` method when projection state is deleted
- `apply(Event $event)` resolves the matching handler, deserializes the payload, and invokes the projection method
- `flush()` runs the `#[ProjectionFlush]` method at the end of each claimed batch

Rule of thumb:

- use `ProjectionDefinition` when you need metadata
- use `ProjectionRuntime` when you need to execute projection code

### `ProjectionCatalog`

[ProjectionCatalog.php](ProjectionCatalog.php) is a registry of runtimes and definitions keyed by projection id.

It is used by Signal integration to map appended events to projection queues and by workers to resolve a runtime
from a claimed signal hint.

## Segment Execution

### `ProjectionRunner`

[ProjectionRunner.php](ProjectionRunner.php) is the low-level executor.

It works with an `EventStore` and a [ProjectionSegmentRepositoryInterface.php](ProjectionSegmentRepositoryInterface.php).

Its job is:

1. open a projection transaction boundary for first-time setup
2. ensure the projection has an initial segment and initialize it when the segment was just created
3. claim a segment, or the segment containing a specific bucket
4. load matching events for that segment from the current checkpoint
5. open a projection transaction boundary
6. apply them through `ProjectionRuntime`
7. flush the projection runtime
8. release the segment with the new checkpoint
9. commit the projection transaction
10. abandon the segment if the transaction fails
11. finalize split/merge operations when needed

The segment repository owns runtime state such as:

- current bucket range
- checkpoint
- lease state
- pending split/merge operations

Segments are represented by [ProjectionSegment.php](ProjectionSegment.php).

`ProjectionRunner` accepts a [ProjectionTransactionManagerInterface.php](ProjectionTransactionManagerInterface.php).
The default [NoOpProjectionTransactionManager.php](NoOpProjectionTransactionManager.php) keeps in-memory and custom
storage usage lightweight. Postgres-backed read models should wire the DBAL-based transaction manager from
`src/Projection_Postgres` with the same connection used by projection read-model repositories and the segment
repository. This makes event application, `#[ProjectionFlush]`, and checkpoint advancement commit or roll back as
one unit. First-time segment creation and `#[ProjectionInitializer]` execution are also wrapped together in the
same transaction manager. Projection deletion likewise wraps `#[ProjectionResetter]` execution and segment-state
deletion together.

## Signal Wakeups

Projection wakeups are Signal-backed. The Signal row is a durable hint that a projection bucket may be stale; it is
not the unit of work and it is not a correctness lock.

### Signal Recording

[ProjectionSignalEventStore.php](Signal/ProjectionSignalEventStore.php) decorates the event store when a
`SignalStore` is available.

It:

1. scans all projection definitions
2. finds which projections handle each committed event
3. computes the touched bucket for each `(projection, event)` pair
4. appends one Signal hint per distinct `(projectionId, bucket)` pair

Important details:

- signals are hints for liveness and scheduling, not the source of truth for correctness
- progress still comes from segment checkpoints in the segment repository
- signal recording is deduplicated per append batch, so one append touching the same bucket many times emits one hint
- writers do not coalesce against existing Signal rows

Explicit rebuilds should not depend on signals. Use `ProjectionRunner::backfillClaimedOnce()` for claimed
segments that must be replayed from their checkpoints regardless of whether activity hints exist.

### Signal Handling

[ProjectionSignalHandler.php](Signal/ProjectionSignalHandler.php) is registered once per discovered projection when
Signal is available.

It:

1. receives one or more `SignalHint` objects for its projection id
2. reads the current segment topology
3. maps hinted buckets to current segments
4. asks `ProjectionRunner::runSignaledOnce()` to claim and process each current segment

The handler does not rely on a range proposed by the poller. Topology is resolved at handling time, so a signal for a
bucket in an old split segment maps to the currently active segment.

`ProjectionRunner::runSignaledOnce()`:

1. claims the projection segment through `ProjectionSegmentRepositoryInterface`
2. captures a Signal visibility snapshot after acquiring the segment claim
3. best-effort suppresses dispatch for the covered bucket range
4. loads and applies matching events from the segment checkpoint
5. flushes the projection runtime
6. acks visible Signal rows in the covered bucket range only after the segment catches up
7. completes or releases the segment claim

Scaling is an infrastructure concern: run more Signal pollers. Duplicate signal delivery and duplicate segment-claim
attempts are expected; the segment repository prevents duplicate expensive processing for the same segment.

### Topology Changes

Split and merge operations update segment topology only. They do not create or delete Signal rows. Existing Signal
rows are interpreted against the current topology when a handler receives them.

## In-Memory Implementations

This package includes in-memory adapters for tests and local composition:

- [InMemoryProjectionSegmentRepository.php](InMemoryProjectionSegmentRepository.php)

These are useful for:

- unit tests
- single-process experiments
- validating segment and replay logic without Postgres

For durable cross-process segment claims, use the adapters in `src/Projection_Postgres`. For durable wakeups, use
the Signal package and `src/Signal_Postgres`.

## End-to-End Flow

At a high level, the runtime works like this:

1. Events are appended successfully to the event store.
2. `ProjectionSignalEventStore` records touched `(projectionId, bucket)` Signal hints.
3. A Signal poller claims hints and routes them to projection Signal handlers.
4. The handler maps each hinted bucket to the current segment topology.
5. `ProjectionRunner::runSignaledOnce()` claims the segment and replays events from checkpoints.
6. Caught-up workers ack visible Signal rows in the covered range.

The key design choice is:

> checkpoints and segment state are the source of truth; Signal rows are liveness hints.
