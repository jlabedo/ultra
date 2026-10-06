# Projection

The `Projection` package defines how read models are described, replayed, and coordinated.

Projection authors must keep read-model writes within the configured projection
transaction, using the same connection as checkpoint persistence. External
effects require a separate delivery mechanism: they cannot be rolled back with
the projection checkpoint. This contract keeps read-model changes and checkpoint
advancement consistent when a batch fails.

It is intentionally split into two layers:

- definition/runtime: what a projection is and how it applies events
- coordination: how workers are woken up and steered toward stale work

Postgres-specific persistence lives in `src/Projection/Postgres`. This package keeps the runtime algorithm storage-agnostic and ships in-memory implementations for tests and local wiring.

## Partition ordering

The partition dimension declares which events require ordered processing. Events
sharing the same value of that dimension are processed in event-store order;
there is no processing-order guarantee across different partition values.
Projection authors must choose a dimension that covers their ordering dependencies.
This enables horizontal scaling and reduces contention. Buckets and segments are
execution units that group logical partitions; they do not establish an ordering
contract between independent partitions.

ProjectionRuntime currently dispatches through handler metadata and dynamic
method calls. This is a deliberate readability trade-off: the expected gain from
generated dispatch does not justify its additional complexity.

## Main Concepts

### `ProjectionDefinition`

[ProjectionDefinition.php](../../src/Projection/Runtime/ProjectionDefinition.php) is the static description of a projection class.

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

[ProjectionRuntime.php](../../src/Projection/Runtime/ProjectionRuntime.php) binds a concrete projection object to its definition and an `UltraNormalizer`.

It is the execution-facing object used by workers:

- `initialize()` runs the `#[ProjectionInitializer]` method once when the projection is first created
- `reset()` runs the `#[ProjectionResetter]` method when projection state is deleted
- `apply(Event $event)` resolves the matching handler, deserializes the payload, and invokes the projection method
- `flush()` runs the `#[ProjectionFlush]` method at the end of each claimed batch

Rule of thumb:

- use `ProjectionDefinition` when you need metadata
- use `ProjectionRuntime` when you need to execute projection code

### `ProjectionCatalog`

[ProjectionCatalog.php](../../src/Projection/Runtime/ProjectionCatalog.php) is a registry of runtimes and definitions keyed by projection id.

It is used by:

- the activity recorder, which needs all definitions
- the coordinator, which needs to resolve a runtime from a touched projection id

## Segment Execution

### `ProjectionRunner`

[ProjectionRunner.php](../../src/Projection/Runtime/ProjectionRunner.php) is the low-level executor.

It works with an `EventStore` and a [ProjectionSegmentRepositoryInterface.php](../../src/Projection/Runtime/ProjectionSegmentRepositoryInterface.php).

Its job is:

1. open a projection transaction boundary for first-time setup
2. ensure the projection has an initial segment and initialize it when the segment was just created
3. claim a segment identified by its first bucket
4. load matching events for that segment from the current checkpoint
5. open a projection transaction boundary
6. apply them through `ProjectionRuntime`
7. flush the projection runtime
8. release the segment with the new checkpoint
9. commit the projection transaction
10. abandon the segment if the transaction fails
Split and merge are separate explicit operations on segment ranges.

The segment repository owns runtime state such as:

- current bucket range
- checkpoint
- lease state

Segments are represented by [ProjectionSegment.php](../../src/Projection/Runtime/ProjectionSegment.php).

Postgres claims use a conditional `UPDATE ... RETURNING` to acquire ownership and
read the segment in one atomic operation. A claim joins an existing connection
transaction: other sessions see ownership only after commit, and rollback restores
the previous owner.

`ProjectionRunner` accepts a [ProjectionTransactionManagerInterface.php](../../src/Projection/Runtime/ProjectionTransactionManagerInterface.php).
The default [NoOpProjectionTransactionManager.php](../../src/Projection/Runtime/NoOpProjectionTransactionManager.php) keeps in-memory and custom
storage usage lightweight. Postgres-backed read models should wire the DBAL-based transaction manager from
`src/Projection/Postgres` with the same connection used by projection read-model repositories and the segment
repository. This makes event application, `#[ProjectionFlush]`, and checkpoint advancement commit or roll back as
one unit. First-time segment creation and `#[ProjectionInitializer]` execution are also wrapped together in the
same transaction manager. Projection deletion likewise wraps `#[ProjectionResetter]` execution and segment-state
deletion together.

## Coordination

The coordination model is intentionally separate from segment execution.

### Activity Recording

[ProjectionActivityRecorder.php](../../src/Projection/Runtime/Coordination/ProjectionActivityRecorder.php) runs after events have been appended successfully.

It:

1. scans all projection definitions
2. finds which projections handle each appended event
3. computes the touched bucket for each `(projection, event)` pair
4. bumps the activity counter for each distinct `(projectionId, bucket)` pair
5. signals work availability through the activator when the coordinator lease allows a debounced wake-up

Append, activity recording and durable wake-up writes must participate in the appropriate shared transaction to stay aligned with commit. Recording after append does not by itself mean the transaction has committed.

Important details:

- activity is a hint for liveness and scheduling, not the source of truth for correctness
- progress still comes from segment checkpoints in the segment repository
- bucket activity is deduplicated per append batch, so one append touching the same bucket many times only bumps it once

### Bucket Activity

[ProjectionActivity](../../src/Projection/Runtime/Coordination/ProjectionActivity.php)
adapts Coordination's DirtyKeyTracker to projection IDs and bucket numbers.
Counters absorb repeated signals. Workers subtract only the counters observed
before processing once their claimed segment catches up, preserving concurrent
signals for a later cycle.

Activity is a scheduling hint; segment checkpoints remain the source of truth
for progress. Explicit rebuilds use ProjectionRunner::backfillClaimedOnce without
requiring activity hints.

### Global Coordinator

[ProjectionCoordinator.php](../../src/Projection/Runtime/Coordination/ProjectionCoordinator.php) is a single leader worker for the whole app.

It does not wake per projection. Instead it:

1. acquires the global coordinator lease
2. reads all affected buckets across all projections
3. decides the target worker/segment count through a scaling policy
4. recovers expired segment leases for the touched projections
5. ensures each touched projection is initialized
6. splits available segments until the projection has enough segments for the requested workers plus itself
7. maps pending bucket activity to affected segments
8. durably claims affected segments for itself or activated workers
9. processes one local claim itself through `ProjectionRunner`
10. workers decrement the bucket counters they observed only after their claimed segment catches up
11. exits when there is no work or no productive progress

This is important: the coordinator is not only a scheduler. It is also a worker and always participates in processing.

Coordinator liveness uses [CoordinatorLease](../../src/Coordination/Runtime/Lease/CoordinatorLease.php).

### Activation

[ProjectionActivatorInterface](../../src/Projection/Runtime/Coordination/ProjectionActivatorInterface.php)
extends Coordination's work-availability signal with activation of a specific
claimed projection worker. MessageHandlingProjectionActivator implements this
through message handling; in-memory activation is available for tests. Activation
is a wake-up mechanism, while scheduling state remains in the repositories.

### Scaling Policy

[ProjectionScalingPolicyInterface.php](../../src/Projection/Runtime/Coordination/ProjectionScalingPolicyInterface.php) receives the current global affected-bucket view and decides how many extra worker wake-ups should be emitted.

The default implementation, [DefaultProjectionScalingPolicy.php](../../src/Projection/Runtime/Coordination/DefaultProjectionScalingPolicy.php), returns `0`.

That means:

- the leader worker still processes work itself
- no follower workers are spawned unless a custom policy says so

## In-Memory Implementations

The current adapters include:

- [InMemoryProjectionSegmentRepository](../../src/Projection/Runtime/InMemory/InMemoryProjectionSegmentRepository.php)
- [InMemoryDirtyKeyTracker](../../src/Coordination/Runtime/InMemory/InMemoryDirtyKeyTracker.php)
- [InMemoryCoordinatorLease](../../src/Coordination/Runtime/InMemory/InMemoryCoordinatorLease.php)
- [InMemoryProjectionActivator](../../src/Projection/Runtime/InMemory/InMemoryProjectionActivator.php)

These support tests and local composition. Durable cross-process coordination
uses the adapters in Projection_Postgres and Coordination_Postgres.

## End-to-End Flow

At a high level, the runtime works like this:

1. Events are appended successfully to the event store.
2. `ProjectionActivityRecorder` records touched `(projectionId, bucket)` pairs.
3. The activator signals work availability for the global coordinator.
4. A worker becomes leader by acquiring the coordinator lease.
5. The leader reads affected buckets, claims affected segments, optionally wakes followers, and processes one claim itself.
6. `ProjectionRunner` claims or reclaims its assigned segment and replays events from its checkpoint.
7. Caught-up workers decrement the bucket counters they observed, and the loop continues until there is no productive work left.

The key design choice is:

> checkpoints and segment state are the source of truth; bucket activity and queue messages are liveness hints.


## Guarantee suites

All projection suites live in [src/Projection/Runtime/Tests](../../src/Projection/Tests), including Postgres and Symfony integration. Memory and Postgres share scenarios where their checkpoint and segment behavior can diverge. See the shared [test-refactoring convention](../development.md#refactoring-tests).

| Guarantee | Suite |
| --- | --- |
| Valid declarations, event routing and optional hooks | [DefinitionContractTest](../../src/Projection/Tests/DefinitionContractTest.php) |
| Initial creation, reset and lifecycle rollback | [ProjectionLifecycleTest](../../src/Projection/Tests/ProjectionLifecycleTest.php) |
| Ordered partition batches, checkpoint resume and replay | [CheckpointProgressTest](../../src/Projection/Tests/CheckpointProgressTest.php) |
| Atomic read-model writes, flush and checkpoint, including caller-owned transactions | [BatchAtomicityTest](../../src/Projection/Tests/BatchAtomicityTest.php) |
| Exclusive segment ownership, expired claims and competing claims across caller commit/rollback | [SegmentOwnershipTest](../../src/Projection/Tests/SegmentOwnershipTest.php) |
| Split/merge ranges, checkpoint gaps and stale completion | [SegmentTopologyTest](../../src/Projection/Tests/SegmentTopologyTest.php) |
| Deduplicated activity and acknowledgement of observed counters | [ActivityAcknowledgementTest](../../src/Projection/Tests/ActivityAcknowledgementTest.php) |
| Leader processing, worker scaling, fairness and loop termination | [CoordinationSchedulingTest](../../src/Projection/Tests/CoordinationSchedulingTest.php) |
| Standalone projection lease expiry and wake debounce | [LeaderLivenessTest](../../src/Projection/Tests/LeaderLivenessTest.php) |
| Concurrent leader/follower execution across independent segments | [ConcurrentExecutionTest](../../src/Projection/Tests/ConcurrentExecutionTest.php) |
| Catalog lookup, Symfony discovery and activity-enabled append | [ProjectionRegistrationTest](../../src/Projection/Tests/ProjectionRegistrationTest.php) |
| Delivery of coordinator wakeups and durable worker claims | [ActivationDeliveryTest](../../src/Projection/Tests/ActivationDeliveryTest.php) |
| Segment listing, process backfill and reset commands | [OperatorCommandsTest](../../src/Projection/Tests/OperatorCommandsTest.php) |

Run the fast suites with `bin/phpunit src/Projection/Tests`; use `--group slow` for the Postgres process scenarios. The process harness remains in [Tests/Postgres/Harness](../../src/Projection/Tests/Postgres/Harness) for direct console use. The event-store and generic coordination contracts remain owned by their contexts; Projection verifies their composition. Lease expiry and takeover belong to [Coordination’s ownership suite](../../src/Coordination/Tests/LeaseOwnershipTest.php).
