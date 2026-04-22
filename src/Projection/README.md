# Projection

The `Projection` package defines how read models are described, replayed, and coordinated.

It is intentionally split into two layers:

- definition/runtime: what a projection is and how it applies events
- coordination: how workers are woken up and steered toward stale work

Postgres-specific persistence lives in `src/Projection_Postgres`. This package keeps the runtime algorithm storage-agnostic and ships in-memory implementations for tests and local wiring.

## Main Concepts

### `ProjectionDefinition`

[ProjectionDefinition.php](ProjectionDefinition.php) is the static description of a projection class.

It is built from attributes and reflection and contains:

- `projectionId`
- `partitionDimension`
- initializer method, if any
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
- `apply(Event $event)` resolves the matching handler, deserializes the payload, and invokes the projection method

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

1. ensure the projection has an initial segment
2. claim a segment, or the segment containing a specific bucket
3. load matching events for that segment from the current checkpoint
4. apply them through `ProjectionRuntime`
5. release the segment with the new checkpoint
6. finalize split/merge operations when needed

The segment repository owns runtime state such as:

- current bucket range
- checkpoint
- lease state
- pending split/merge operations

Segments are represented by [ProjectionSegment.php](ProjectionSegment.php).

## Coordination

The coordination model is intentionally separate from segment execution.

### Activity Recording

[ProjectionActivityRecorder.php](Coordination/ProjectionActivityRecorder.php) runs after events have been appended successfully.

It:

1. scans all projection definitions
2. finds which projections handle each committed event
3. computes the touched bucket for each `(projection, event)` pair
4. bumps the activity counter for each distinct `(projectionId, bucket)` pair
5. enqueues one global `WakeProjectionCoordinator` message if no recent wake-up is already pending

Important details:

- activity is a hint for liveness and scheduling, not the source of truth for correctness
- progress still comes from segment checkpoints in the segment repository
- bucket activity is deduplicated per append batch, so one append touching the same bucket many times only bumps it once

### Bucket Activity

[ProjectionBucketActivityRepositoryInterface.php](Coordination/ProjectionBucketActivityRepositoryInterface.php) stores touched buckets as [ProjectionBucketActivity.php](Coordination/ProjectionBucketActivity.php).

Each row is effectively a generation signal:

- `counter` is bumped when the bucket is touched again
- `acknowledgedCounter` records what the coordinator or worker has already caught up through

The coordinator asks for global `affectedBuckets()`, not for all projections in the catalog. This keeps the hot path centered on known stale work.

### Global Coordinator

[ProjectionCoordinator.php](Coordination/ProjectionCoordinator.php) is a single leader worker for the whole app.

It does not wake per projection. Instead it:

1. acquires the global coordinator lease
2. reads all affected buckets across all projections
3. decides whether to wake extra workers through a scaling policy
4. recovers expired segment leases for the touched projections
5. processes bucket-targeted work itself through `ProjectionRunner`
6. acknowledges buckets it successfully caught up
7. exits when there is no work or no productive progress

This is important: the coordinator is not only a scheduler. It is also a worker and always participates in processing.

Coordinator liveness is abstracted behind [ProjectionCoordinatorLeaseRepositoryInterface.php](Coordination/ProjectionCoordinatorLeaseRepositoryInterface.php).

### Wake Queue

[ProjectionWakeQueueInterface.php](Coordination/ProjectionWakeQueueInterface.php) is deliberately tiny:

- `enqueue(object $message)`
- `dequeue(): ?object`

Today the package defines two wake-up messages:

- [WakeProjectionCoordinator.php](Coordination/WakeProjectionCoordinator.php)
- [WakeProjectionWorker.php](Coordination/WakeProjectionWorker.php)

The queue is only a wake-up mechanism. Scheduling truth remains in the repositories.

### Scaling Policy

[ProjectionScalingPolicyInterface.php](Coordination/ProjectionScalingPolicyInterface.php) receives the current global affected-bucket view and decides how many extra worker wake-ups should be emitted.

The default implementation, [DefaultProjectionScalingPolicy.php](Coordination/DefaultProjectionScalingPolicy.php), returns `0`.

That means:

- the leader worker still processes work itself
- no follower workers are spawned unless a custom policy says so

## In-Memory Implementations

This package includes in-memory adapters for tests and local composition:

- [InMemoryProjectionSegmentRepository.php](InMemoryProjectionSegmentRepository.php)
- [InMemoryProjectionBucketActivityRepository.php](Coordination/InMemoryProjectionBucketActivityRepository.php)
- [InMemoryProjectionCoordinatorLeaseRepository.php](Coordination/InMemoryProjectionCoordinatorLeaseRepository.php)
- [InMemoryProjectionWakeQueue.php](Coordination/InMemoryProjectionWakeQueue.php)

These are useful for:

- unit tests
- single-process experiments
- validating coordination logic without Postgres

For durable cross-process coordination, use the adapters in `src/Projection_Postgres`.

## End-to-End Flow

At a high level, the runtime works like this:

1. Events are appended successfully to the event store.
2. `ProjectionActivityRecorder` records touched `(projectionId, bucket)` pairs.
3. A single global coordinator wake-up is enqueued.
4. A worker becomes leader by acquiring the coordinator lease.
5. The leader reads affected buckets, optionally wakes followers, and processes work itself.
6. `ProjectionRunner` claims real segments from the segment repository and replays events from checkpoints.
7. Processed buckets are acknowledged, and the loop continues until there is no productive work left.

The key design choice is:

> checkpoints and segment state are the source of truth; bucket activity and queue messages are liveness hints.
