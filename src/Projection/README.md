# Projection

The `Projection` package defines how read models are described, replayed, and coordinated.

The current coordination implementation is due for a rewrite. Use
[COORDINATION_REWRITE_SPEC.md](COORDINATION_REWRITE_SPEC.md) as the source of
truth for the next coordination design and for deciding which existing
coordination tests should be discarded or replaced.

It is intentionally split into two layers:

- definition/runtime: what a projection is and how it applies events
- coordination: how workers are woken up and steered toward stale work

Postgres-specific persistence lives in `src/Projection_Postgres`. This package keeps the runtime algorithm storage-agnostic and ships in-memory implementations for tests and local wiring.

## Main Concepts

### `ProjectionDefinition`

[ProjectionDefinition.php](ProjectionDefinition.php) is the static description of a projection class.

It is built from attributes and reflection and contains:

- `projectionId`
- optional `partitionDimension`
- initializer method, if any
- flush method, if any
- handler metadata per event type

It also knows how to:

- list handled event types
- check whether an event is handled
- compute the event bucket from the partition dimension, or use the single ordered lane for unpartitioned projections
- build the event-store query for a whole projection or one segment

### `ProjectionRuntime`

[ProjectionRuntime.php](ProjectionRuntime.php) binds a concrete projection object to its definition and an `UltraNormalizer`.

It is the execution-facing object used by workers:

- `initialize()` runs the `#[ProjectionInitializer]` method once when the projection is first created
- `apply(Event $event)` resolves the matching handler, deserializes the payload, and invokes the projection method
- `flush()` runs the `#[ProjectionFlush]` method at the end of each claimed batch

Rule of thumb:

- use `ProjectionDefinition` when you need metadata
- use `ProjectionRuntime` when you need to execute projection code

### `ProjectionCatalog`

[ProjectionCatalog.php](ProjectionCatalog.php) is a registry of runtimes and definitions keyed by projection id.

It is used by:

- the activity recorder, which needs all definitions
- the coordinator, which needs to resolve a runtime from a touched projection id

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
same transaction manager.

## Coordination

The coordination model is intentionally separate from segment execution.

### Activity Recording

[ProjectionActivityRecorder.php](Coordination/ProjectionActivityRecorder.php) runs after events have been appended successfully.

It:

1. scans all projection definitions
2. finds which projections handle each committed event
3. computes the touched bucket for each `(projection, event)` pair
4. bumps the activity counter for each distinct `(projectionId, bucket)` pair
5. enqueues one coarse `WakeProjectionWork` message if no recent wake-up is already pending

Important details:

- activity is a hint for liveness and scheduling, not the source of truth for correctness
- progress still comes from segment checkpoints in the segment repository
- bucket activity is deduplicated per append batch, so one append touching the same bucket many times only bumps it once

### Bucket Activity

[ProjectionBucketActivityRepositoryInterface.php](Coordination/ProjectionBucketActivityRepositoryInterface.php) stores touched buckets as [ProjectionBucketActivity.php](Coordination/ProjectionBucketActivity.php).

Each row is effectively a generation signal:

- `counter` is bumped when the bucket is touched again
- workers decrement the exact pending counter value they observed after their claimed segment catches up

The coordinator asks for global `affectedBuckets()` to find stale projections, maps those hints to available
segments, and durably claims only segments that still have pending bucket activity. Bucket activity is a liveness
signal; segments are the scheduling and checkpointing unit.

### Worker-Pull Coordinator

[ProjectionCoordinator.php](Coordination/ProjectionCoordinator.php) is the worker-pull loop.

Every worker runs the same loop:

1. reads affected buckets across projections
2. ensures touched projections are initialized
3. recovers expired segment leases for touched projections
4. splits available segments when pending buckets can be exposed to more workers
5. atomically claims one available segment that still has pending activity
6. processes that claim through `ProjectionRunner` until it catches up
7. repeats while it keeps finding productive work
8. exits when there is no work or no claimable work

There is no global leader on the normal processing path. Queue wakeups invite any
worker to pull work from durable coordination state; they do not assign internal
segments to specific workers.

The coordinator lease repository is still used by activity recording to debounce
coarse wake signals. It is no longer used to decide which worker may process
projection work.

### Wake Messages

The package defines one queue-facing wake message:

- [WakeProjectionWork.php](MessageHandling/WakeProjectionWork.php)

The queue is only a wake-up mechanism. Scheduling truth remains in the repositories.

### Scaling Policy

The current scaling policy abstraction is retained only as a compatibility seam
while the rewrite proceeds. The worker-pull loop does not use it to spawn
followers. Future scaling work should be based on automatic claimable-work
discovery rather than leader-assigned worker counts.

## In-Memory Implementations

This package includes in-memory adapters for tests and local composition:

- [InMemoryProjectionSegmentRepository.php](InMemoryProjectionSegmentRepository.php)
- [InMemoryProjectionBucketActivityRepository.php](Coordination/InMemoryProjectionBucketActivityRepository.php)
- [InMemoryProjectionCoordinatorLeaseRepository.php](Coordination/InMemoryProjectionCoordinatorLeaseRepository.php)
- [InMemoryProjectionActivator.php](InMemoryProjectionActivator.php)

These are useful for:

- unit tests
- single-process experiments
- validating coordination logic without Postgres

For durable cross-process coordination, use the adapters in `src/Projection_Postgres`.

## End-to-End Flow

At a high level, the runtime works like this:

1. Events are appended successfully to the event store.
2. `ProjectionActivityRecorder` records touched `(projectionId, bucket)` pairs.
3. A coarse projection-work wake-up is enqueued.
4. Any active worker can receive the wake-up or poll for work.
5. The worker reads affected buckets, claims one available segment, and processes it.
6. `ProjectionRunner` renews the claimed segment lease and replays events from checkpoints.
7. Caught-up workers decrement the bucket counters they observed, and the loop continues until there is no productive work left.

The key design choice is:

> checkpoints and segment state are the source of truth; bucket activity and queue messages are liveness hints.
