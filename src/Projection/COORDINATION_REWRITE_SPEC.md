# Projection Coordination Rewrite Specification

This document is the working specification for replacing the current projection
coordination implementation.

The goal is not to preserve the current coordinator, segment, and scaling-policy
model. The goal is to preserve the product guarantees that matter, discard
accidental complexity, and provide a stable base for rewriting the tests.

## Problem Statement

Projection coordination currently mixes several responsibilities in one runtime
path:

- detecting stale projection work
- deciding how much parallelism to use
- assigning work to workers
- executing claimed work
- maintaining internal partition state
- debouncing queue wakeups

This makes the behavior hard to reason about and causes tests to encode
implementation choreography instead of durable requirements.

The rewrite should make normal projection execution pull-based and leaderless.
Workers should claim available work directly from durable coordination state.
Queue messages should wake workers, not assign specific internal partitions.

## Vocabulary

### Projection

A user-defined read model updated from handled event-store events.

The user-facing projection definition includes:

- a stable projection id
- the handled event types
- an optional partition dimension
- handler methods
- optional initialization and flush hooks

### Partition Key

The domain identifier used to order and distribute projection work.

For example, a projection declared with `partitionBy: 'courseId'` requires events
for the same `courseId` to be processed in event-store order by that projection.
Events for different `courseId` values may be processed concurrently.

### Internal Partition

An implementation-owned unit of projection progress and leasing.

The public API must not expose the internal partitioning model. The current code
uses segments and bucket ranges, but the rewrite is free to replace that model.

### Worker

A process capable of claiming and processing projection work.

Workers are homogeneous. A worker is not a leader, follower, coordinator child,
or segment-specific process from the user's point of view.

### Wake Signal

A queue message or equivalent notification that tells projection workers that
work may exist.

A wake signal is a liveness hint. It is not the source of correctness.

## Core Guarantees

### Projection Application

Projection handlers that update storage participating in the same transaction as
coordination state should be applied exactly once.

That means the following changes must commit or roll back together:

- read-model writes
- projection flush work
- checkpoint advancement
- release or renewal of the claimed work lease

When a projection writes to storage that cannot participate in the same
transaction, the guarantee is at least once. In that mode, duplicate application
must be considered possible after crashes, retries, lease expiry, or queue
redelivery.

The runtime should make this distinction explicit in documentation and, if
practical, in configuration.

### Ordering

Ordering is required only per projection and partition key.

For a projection partitioned by `courseId`, all handled events for the same
`courseId` must be applied in event-store order for that projection.

Events for different partition-key values may be processed concurrently.

Global ordering across all events of a projection is not required unless the
projection has no partition dimension or explicitly opts into single-lane
execution.

### Liveness

If matching events are committed to the event store, an active projection worker
must eventually discover and process them.

This must remain true when:

- a queue wake signal is lost
- a queue wake signal is duplicated
- no worker is running at append time
- a worker crashes while holding a lease
- the desired concurrency changes while work is pending

### Queue Semantics

Queue integration is part of the core projection package because it is the main
runtime use case.

The MessageHandling package should remain optional. The projection package may
define queue-facing messages and abstractions, while MessageHandling integration
adapts those messages to the mediator when installed.

Queue messages are wake signals. They should not contain internal partition
assignments.

Preferred message shape:

```php
final readonly class WakeProjectionWork
{
    public function __construct(
        public string $workerId,
    ) {}
}
```

or a similarly coarse signal.

Avoid messages shaped like:

```php
RunProjectionSegment($projectionId, $bucketFrom, $workerId)
```

because they expose internal partitioning and couple queue backlog to a
particular scheduling decision.

### Queue Pollution Control

Recording activity for a burst of events must not enqueue a proportional number
of duplicate wake messages.

The system should coalesce wakeups. Acceptable strategies include:

- one outstanding global wake signal
- one outstanding wake signal per projection
- a short debounce window backed by durable state

The chosen strategy must be safe under concurrency. Multiple appenders racing to
record projection activity should enqueue at most a small bounded number of wake
signals for the same work burst.

Duplicate wake messages must be harmless.

### Polling Fallback

Workers must support polling durable coordination state when no queue activity
arrives.

Polling should not spin hot when there is no work. The worker loop should support
bounded backoff with jitter.

Queue wakeups and polling are complementary:

- queue wakeups reduce latency after appends
- polling provides recovery after missed wakeups, stopped workers, and queue
  outages

## User-Facing Concurrency

Static worker count is not an acceptable long-term user model.

The system should support automatic or dynamic concurrency. The user should not
need to choose internal segment counts or bucket ranges.

Possible public models:

- no explicit concurrency setting, with automatic defaults
- a coarse policy such as `parallelism: auto`
- an optional cap such as `maxWorkers: 8`
- an operational setting for the whole projection subsystem rather than per
  projection

The first rewrite may implement a simple dynamic algorithm, but it must not make
static user-chosen segment counts part of the public API.

### Required Dynamic Behavior

The implementation must tolerate concurrency changes without resetting
projections.

Increasing available workers should allow more independent partition keys to
make progress concurrently.

Decreasing available workers should not require repartitioning to complete
before processing can continue.

Workers should be able to join or leave at any time. Durable leases are the
recovery mechanism for abandoned work.

## Internal Partitioning

The implementation must not assume that a fixed internal bucket count is forever
correct.

A fixed bucket space may be used as an initial implementation detail only if the
design permits later replacement or expansion without changing user-facing
projection definitions.

The partitioning strategy must preserve:

- per-partition-key ordering
- checkpoint correctness
- lease recovery
- dynamic worker participation

The implementation should prefer the simplest maintainable strategy that meets
those guarantees.

## Worker Execution Model

Normal processing should be leaderless.

A worker loop should:

1. wake from a queue message, command, or polling interval
2. ask durable coordination state for claimable projection work
3. atomically claim one unit of work with a lease
4. process a bounded batch from the claimed checkpoint
5. apply events and flush the projection
6. advance the checkpoint in the same transaction when possible
7. release the claim when caught up, or renew it when more work remains
8. repeat while productive
9. back off when no work is available

Any active worker should be able to claim any compatible work.

Workers should not require a leader to assign them work.

## Optional Planner Role

A leader-like component may exist only as an optional planner or maintenance
role, not as the required path for normal processing.

Valid planner responsibilities may include:

- cleaning stale activity records
- measuring projection lag
- adjusting scheduling hints
- preparing internal partition metadata
- emitting coarse wake signals if the system appears idle while work exists

The planner must not be required for every burst of projection work to make
progress.

If the planner is stopped, normal workers must still be able to process existing
and newly discovered work through queue wakeups and polling.

## Activity Tracking

Activity tracking is a liveness and scheduling hint, not the source of truth.

When events are appended successfully, the activity recorder should:

1. inspect projection definitions
2. identify projections that handle each event
3. derive the partition key when available
4. record that matching projection work may exist
5. coalesce a wake signal

Projection checkpoints remain the source of truth for progress.

It must be safe for activity tracking to over-report work. It is acceptable for a
worker to wake, claim nothing, and back off.

It must not be permanently unsafe for activity tracking to under-report work if
polling can rediscover lag from checkpoint state.

## Projections Without Partition Keys

Projections without a usable partition key are allowed.

They must run in a single ordered lane unless the projection explicitly provides
another safe partitioning strategy.

For these projections:

- global ordering of handled events is required
- concurrency for that projection is effectively one
- multiple workers may race to claim the lane, but only one may hold the lease

## Backfill And Live Processing

Backfill and live processing should use the same core execution machinery unless
that creates substantial complexity.

The preferred distinction is loop policy, not separate projection semantics:

- backfill runs until caught up or until an operator-provided limit
- live processing continues waking, polling, and backing off

Both modes should use the same checkpoint, lease, ordering, and transaction
guarantees.

## Failure Handling

### Worker Crash

If a worker crashes while holding work, another worker must be able to reclaim
that work after lease expiry.

Lease expiry must not allow two workers to commit conflicting checkpoint
advancements for the same work unit.

### Handler Failure

If a projection handler throws, the claimed work should be released or left to
expire according to a clear retry policy.

Checkpoint advancement must not commit if the projection transaction failed.

### Queue Failure

If queue dispatch fails after activity was recorded, polling must eventually
discover the work.

If queue dispatch succeeds but the message is delivered multiple times, only one
worker should claim any given unit of work at a time.

### External Storage

When projection writes target storage outside the coordination transaction,
failures may produce duplicate external writes.

Such projections require idempotent handlers or external deduplication.

## Observability

The rewrite should expose enough state to understand production behavior without
reading internal tables by hand.

Useful observable values include:

- projection lag
- claim owner and lease expiry
- number of claimable work units
- number of active workers
- last processed checkpoint per projection/internal partition
- wake signals emitted, coalesced, and ignored
- handler failures and retry counts

The public observability language should use projection and worker concepts, not
internal segment implementation details.

## Requirements To Keep From The Current Implementation

Keep these requirements:

- projection checkpoints are durable source of truth
- activity and queue messages are liveness hints
- duplicate wakeups are harmless
- wakeups are coalesced
- claims are lease-based and recoverable
- processing is bounded by batch size
- first-time initialization is transactional with projection state when possible
- tests must cover concurrent workers and lease recovery
- Postgres remains the durable cross-process implementation
- in-memory implementations remain useful for unit tests

## Requirements To Discard Or Reconsider

Discard these as product requirements:

- a global coordinator lease for normal processing
- leader and follower worker roles
- a leader processing one local assignment while activating remote assignments
- segment-specific queue messages
- user-visible segment or bucket configuration
- tests that assert exact segment split counts
- tests that assert synthetic worker id formats
- dynamic split and merge as the primary scaling mechanism

Reconsider these before reintroducing them:

- `ProjectionScalingPolicyInterface` as "how many extra workers should the
  leader wake?"
- runtime segment splitting during the hot processing path
- a global affected-bucket scan as the only way to discover work
- making activity counters responsible for exact progress acknowledgement

## Replacement Test Specification

The new tests should describe behavior rather than the old choreography.

### Correctness Tests

- A projection applies each event exactly once when read-model writes and
  checkpoint advancement share a transaction.
- A failed projection transaction does not advance the checkpoint.
- A projection targeting external storage is documented and tested as at least
  once.
- Events for the same partition key are applied in event-store order.
- Events for different partition keys can be processed by different workers.
- A projection without a partition key processes handled events in one ordered
  lane.

### Worker Claim Tests

- Two workers cannot hold the same claim at the same time.
- A worker can reclaim its own lease while continuing a long batch.
- A different worker can reclaim abandoned work after lease expiry.
- A stale worker cannot overwrite a checkpoint committed by the new lease owner.
- Workers can join while work is pending and claim independent work.
- Workers can leave without requiring repartitioning before progress continues.

### Queue And Polling Tests

- Recording many events in one append batch emits a bounded number of wake
  signals.
- Concurrent activity recorders coalesce wake signals.
- Duplicate wake messages do not duplicate claimed work.
- Lost wake messages are recovered by polling.
- With no work, polling backs off instead of looping until a max-cycle limit.
- With zero active workers at append time, later workers discover the work.

### Dynamic Concurrency Tests

- Increasing available workers increases concurrent progress for independent
  partition keys.
- Decreasing available workers does not lose checkpoints.
- Concurrency changes do not require user-visible segment configuration.
- Internal partition metadata can change without changing projection
  definitions.

### Backfill Tests

- Backfill and live processing advance the same checkpoints.
- Backfill can run with multiple workers when partition keys permit.
- Backfill for an unpartitioned projection remains single-lane.

### Integration Tests

- Postgres coordination supports concurrent workers across processes.
- Postgres lease expiry follows the injected clock.
- Postgres transactions commit read-model writes and checkpoint advancement
  together.
- MessageHandling integration can dispatch and handle coarse projection wake
  messages when the optional package is installed.

## Open Design Decisions

The rewrite still needs concrete choices for:

- how to represent claimable work internally
- how workers discover lag without relying only on activity rows
- how to compute automatic concurrency
- whether concurrency is capped globally, per projection, or both
- whether partition metadata is created eagerly or lazily
- how to expose exactly-once versus at-least-once projection modes
- whether a planner process is useful in the first implementation

The default answer should be the simplest implementation that preserves the
guarantees in this document.

## Recommended Direction

Build a leaderless worker-pull runtime first.

Use coarse queue wakeups and polling fallback for liveness.

Keep internal partitioning private and replaceable.

Treat automatic scaling as a scheduling concern layered over durable claims, not
as a reason to put a leader back into the normal processing path.
