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

## Execution modes

```php
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\ProjectionMode;

#[Projection(
    name: 'account_balance',
    partitionBy: 'accountId',
    mode: ProjectionMode::Synchronous,
)]
final class AccountBalanceProjection
{
    // ProjectionHandler methods update projection-owned tables.
}
```

| Mode | Application | History and activation |
| --- | --- | --- |
| `Asynchronous` (default) | Workers apply persisted batches | Segment checkpoints track progress |
| `Synchronous` | Append transactions apply events once the projection is live | Reconstruct in `building`, then explicitly activate |

The execution mode is fixed for a projection identifier and its read model. To replace an
asynchronous projection with a synchronous one, give the new projection its own identifier
and read model, then reconstruct and activate it. Changing the mode in place is outside
the contract and goes undetected by asynchronous execution. Deployments are responsible
for enforcing this rule.

`partitionBy` is required in both modes. Managed synchronous appends reject
handled events missing that identifier. Historical events must also carry it
to participate in partitioned reconstruction.

Synchronous execution inherits the event store's concurrency guarantees. It does
not serialize writers by projection partition, bucket or checkpoint. Concurrent
handlers updating the same row must use appropriate atomic operations or storage
locking. Replay can choose a different order between independent events; reproducible
read models need commutative effects or a deterministic conflict rule.

## Projection handlers

Annotate a public method with `#[ProjectionHandler]` and type its first parameter with the
event class marked `#[Event]`. The runtime passes the deserialized payload. To access
metadata, declare a non-nullable, non-variadic second `array` parameter passed by value, as in
`onAccountCredited(AccountCredited $event, array $metadata)`. The projection definition
validates the signature, including during Symfony container compilation.

The runtime passes the `Event::$metadata` array as received. For live synchronous
execution the array comes from the event supplied to `append()`. Workers and managed
reconstruction receive the array from the event loaded from storage. See the
[event metadata contract](../event-sourcing/event-store.md#event-metadata) for the default
value and the responsibilities of direct append callers.

## Transactional append integration

[ProjectingEventStore](../../src/Projection/Runtime/ProjectingEventStore.php) is the
single append integration, replacing the activity-only decorator. The composition is:

```text
Direct application -> ProjectingEventStore -> persistent EventStore
Buffered application -> EventStoreUnitOfWork -> ProjectingEventStore -> persistent EventStore
```

It matches synchronous event types before opening a projection transaction. With no
synchronous match, it computes the touched activity buckets in memory. If these are
also empty, the inner event store owns its ordinary standalone append transaction.
An existing caller transaction still becomes rollback-only on an append failure in PostgreSQL.

When there is projection work, the decorator opens or joins a transaction, acquires any required activation barriers,
persists the events under their original append conditions, applies live synchronous
projections and flushes their private persistence participants, then records activity
for asynchronous/building projections. An inner store's standalone append transaction
must not commit before projection writes: use the same connection for all adapters.
Synchronous projection failure aborts the append. PostgreSQL marks a joined transaction
rollback-only on failure, even if a caller catches the exception.

The ordinary application `UnitOfWorkRunner` stays unchanged. No global preparation
phase or dependency on parent composite participant order is introduced.

## Projection-owned persistence

[ProjectionRuntime::applyBatch()](../../src/Projection/Runtime/ProjectionRuntime.php)
shares batch application between append, worker execution and activation. It calls
`apply()` for each event, the optional `ProjectionFlush` hook, and then the projection's
persistence participants. Buffers are cleared on both success and failure. All writes
remain in the enclosing transaction; an outer rollback undoes successful batch writes too.

A projection can write SQL directly through the transaction's connection. For buffering,
it can implement `Ultra\UnitOfWork\UnitOfWork` itself, or declare private repository
service IDs:

```php
#[Projection(
    name: 'account_balance',
    partitionBy: 'accountId',
    mode: ProjectionMode::Synchronous,
    unitOfWork: [AccountBalanceRepository::class],
)]
```

The hook may finalize accumulated operations; repositories perform the actual persistence.
Symfony removes owned participants from the parent composite collection. A participant
cannot be shared between projections. Without Symfony, pass participants as the fourth
argument to `ProjectionRuntime`; a projection implementing `UnitOfWork` is included
automatically. Do not also put them in the application composite. They must be dedicated
to projection writes and must not feed work into other independently flushed participants.
Projection handlers must not append new domain events or perform irreversible external effects.

### Mapped row sets in Symfony

Compile the projection's buffers into a [named MappedTable coordinator](../stored-state/mapped-table.md#declare-a-row-set).
Inject a buffer into a repository by its service ID. The projection receives the repository by constructor
injection. Register the coordinator as a persistence participant on the projection service.

```yaml
services:
    App\Projection\DocumentLibraryProjection:
        autowire: true
        autoconfigure: true
        tags:
            - { name: ultra.projection.persistence, service: library.persistence }
```

The runtime runs participants in declaration order (attribute entries, service tags,
then the projection itself if it implements `UnitOfWork`). A runtime processes one batch at a time.
Handlers, replay, initialization and reset reuse the same services. The application coordinator retains
its own buffers throughout a synchronous append. Configure the projection's repository with its dedicated
buffer. Commands can use a separate read interface for the projected data.

PostgreSQL participants must use the transaction's connection. Symfony checks the built-in PostgreSQL
adapters' direct connection references. Custom factories and supplied instances must use the same connection.
The [Symfony event sourcing example](../../examples/symfony-event-sourcing/README.md) exercises a
repository, a shared projection buffer and a transactional reset.

## Managed reconstruction

[ProjectionRebuilder](../../src/Projection/Runtime/ProjectionRebuilder.php) operates
on `Synchronous` projections:

```php
$rebuilder->catchUp($runtime, workerId: 'rebuild-1', batchSize: 100);
$rebuilder->activate($runtime, batchSize: 100);
```

`catchUp()` initializes or resumes segment progress without requiring activity hints.
It leaves the projection in `building`; new appends keep accumulating in the event store.
A segment already claimed by another worker is skipped and may be resumed later.

`activate()` holds an exclusive transaction-scoped barrier for the whole projection,
waits for writers and worker application transactions, takes over scheduling claims,
rereads segment checkpoints, drains the remaining history, and persists `live` in the
same transaction. A rollback restores both model contents and building progress.
Call it after reducing the backlog because the final drain temporarily blocks writers.

Workers hold a shared barrier before claiming, loading and applying a managed batch
and until the transaction ends. They check the current phase and definition under
that barrier and replay only while the projection is building. Ordinary writers use
compatible shared barriers, so they remain concurrent. Live synchronous appends
leave segment checkpoints alone. The coordinator discards remaining activity after
activation.

`ProjectionRunner::deleteProjection()` resets a managed projection under the exclusive
barrier, increments its generation, and initializes new building segments. Readers may
observe the incomplete read model until reactivation. Serving an older generation during
reconstruction is not supported.

Symfony supplies `ultra:projection:rebuild <projection-id>` to resume reconstruction.
Use `--activate` to drain and activate, `--reset` to start a new generation, and
`--batch-size` / `--worker-id` to configure replay.

### Storage and deployment

`ProjectionStateRepository` stores the managed generation, phase, and structural
fingerprint. The fingerprint covers the partition dimension and handled event types.
PostgreSQL uses `READ COMMITTED`, transaction-scoped advisory barriers,
and the `states` table, included in `PostgresProjectionSegmentSchema::createStatements()`.
Apply the managed state schema before deploying synchronous definitions to writers or workers.

Declare PostgreSQL projection storage in `config/packages/ultra.yaml`:

```yaml
ultra:
    projection:
        postgres:
            connection: doctrine.dbal.default_connection
            table_prefix: reporting_
```

Set `connection` to the Symfony service ID of the DBAL connection. The storage implementation and the
[Schema migration command](../schema/README.md#from-symfony) both use `table_prefix`, which defaults to
`projection_`. The command generates migrations for the `states`, `segments`, `dirty_keys` and
`coordinator_lease` tables offline. Table identities stay stable when the prefix changes. Use an
unqualified prefix and select the target PostgreSQL schema through `search_path` when applying the migration.

When wiring storage by hand in Symfony, register `PostgresProjectionSchema::definition()` as a
[schema fragment](../schema/README.md#symfony-discovery) with the same prefix. The method can also supply
the four tables to a manually composed schema. Its table definitions are shared with the PostgreSQL
creation helpers.

An append with no locally declared synchronous projection handling the batch skips all
projection barriers and state reads. Event persistence and asynchronous activity recording
still share the transaction. When there are matches, the writer takes the shared
registration barrier, acquires a shared projection barrier for each match in ID order,
then reads their states together. It checks each registered match's fingerprint and
applies the live matches in the same order.

Before activating a projection, deploy compatible definitions to every writer and drain
older writers. Keep those definitions aligned while the projection is live. A writer
that omits the projection, declares it asynchronous or no longer handles the event can
commit the event without updating the read model. Coordinate code deployments and
explicitly rebuild when handler semantics change. Handler changes can leave the
fingerprint unchanged. Managed definitions remain registered.

The in-memory state repository and no-op transaction manager provide single-process
test/local composition, not database rollback or cross-process concurrency guarantees.

The architecture and rejected alternatives are recorded in
[ADR 0004](../adr/0004-synchronous-projections.md).

## Partition ordering

For worker execution, the partition dimension declares which events require ordered processing. Events
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

It is built from attributes. Symfony validates and compiles the handler metadata during container
compilation. Manual assembly builds the same definition through reflection. The definition contains:

- `projectionId`
- `partitionDimension`
- initializer method, if any
- resetter method, if any
- flush method, if any
- handler metadata per event type
- the structural fingerprint used for managed activation

It also knows how to:

- list handled event types
- check whether an event is handled
- compute the event bucket from the partition dimension
- build the event-store query for a whole projection or one segment

### `ProjectionRuntime`

[ProjectionRuntime.php](../../src/Projection/Runtime/ProjectionRuntime.php) binds a concrete projection object to its definition and an `UltraNormalizer`.

It is the execution-facing object shared by workers and synchronous append execution:

- `initialize()` runs the `#[ProjectionInitializer]` method once when a worker-managed projection is first created
- `reset()` runs the `#[ProjectionResetter]` method when projection state is deleted
- `apply(Event $event)` resolves the matching handler, deserializes the payload, and invokes the projection method
- `flush()` runs the `#[ProjectionFlush]` method before batch persistence

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
Split and merge are separate explicit operations on segment ranges. They take an
exclusive lock per projection to serialize topology changes in both modes.

The runner uses [event-store pagination](../event-sourcing/event-store.md#eventstore-interface) to determine catch-up:
`hasMore = false` completes the claim and permits acknowledgement of observed activity, including for a full final page.

Asynchronous initialization, claiming, replay and reset use segment storage directly.
Asynchronous operations skip managed state lookups and the registration barrier.

`runClaimedBatches()` checks initialization once and yields one result per attempted batch until the segment
catches up or a claim fails. After the first batch, the sequence renews its existing claim. The coordinator
consumes the sequence for its local segment. The segment message handler stops at its configured cycle limit.

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

[ProjectionActivityRecorder.php](../../src/Projection/Runtime/Coordination/ProjectionActivityRecorder.php) records bucket activity and wake-ups inside the append transaction after event insertion and synchronous application. Live synchronous projections are excluded. With no synchronous match, the decorator computes the touched buckets before the append and reuses them when recording activity.

It:

1. scans all projection definitions
2. finds which projections handle each appended event
3. computes the touched bucket for each `(projection, event)` pair
4. bumps the activity counters for all distinct `(projectionId, bucket)` pairs in one batch
5. signals work availability through the activator when the coordinator lease allows a debounced wake-up

Append, activity recording and durable wake-up writes must participate in the appropriate shared transaction to stay aligned with commit. Recording after append does not by itself mean the transaction has committed.

Important details:

- activity is a hint for liveness and scheduling, not the source of truth for correctness
- progress still comes from segment checkpoints in the segment repository
- bucket activity is deduplicated per append batch, so one append touching the same bucket many times only bumps it once

### Bucket Activity

[ProjectionActivity](../../src/Projection/Runtime/Coordination/ProjectionActivity.php)
adapts Coordination's DirtyKeyTracker to projection IDs and bucket numbers.
Counters absorb repeated signals. A worker reads the counters after its first
claim and before loading the first batch. At catch-up, it subtracts only those
observed counters. Signals recorded during the sequence remain pending for a
later cycle, even when the worker has already applied their events. A change to
the segment's range or checkpoint between batches discards the observation.

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

## Symfony integration

`ProjectionExtension` selects the default `ProjectionActivatorInterface` and `ProjectionScalingPolicyInterface`
services during compilation. Application definitions and aliases take precedence, whether registered before
or after the extension is built. Without an explicit activator, a declared `MediatorInterface` service selects
`MessageHandlingProjectionActivator`; otherwise the extension uses `InMemoryProjectionActivator`, even if the
MessageHandling package is installed. The default scaling policy requests no additional workers.

Supply the application's `EventStore` and `ProjectionImplementationInterface` services. The projection
implementation creates its persistence and coordination adapters; declared projection handlers also require Mapper.

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

> For worker processing, checkpoints and segment state are the source of truth; bucket activity and queue messages are liveness hints. Live synchronous projections use the append transaction instead.

## Asynchronous projection benchmarks

The [benchmark runner](../../src/Projection/Tests/Benchmark/async.php) uses three asynchronous
inventory projections with overlapping subscriptions and distinct partition dimensions.
The warehouse projection accumulates results in PHP. The supplier projection groups writes
in a projection flush. The item projection buffers [MappedTable increments](../stored-state/mapped-table.md#incrementing-integer-columns)
when the checkout supports them. The `direct` variant writes one PostgreSQL upsert per event
and is the default on older checkouts. Some events match none of the projections.

| Phase | Timed work |
| --- | --- |
| Append | Raw EventStore, then the revision's projection decorator with matching or unmatched events. The decorated cases also run in caller-owned transactions to control for transaction grouping. |
| Worker | A single worker per projection claims and processes the same seeded event history to catch-up, in batches of 1, 32 or 128 events by default. Each projection has its own result. |

The worker phase uses `ProjectionRunner::runClaimedBatches()` when available and calls
`runClaimedOnce()` until catch-up on earlier revisions. Each yielded attempt counts as one
operation. Activation uses the in-memory signal adapter, so queue transport and worker
contention are outside the benchmark.
The runner prepares fixtures and validates actual outputs outside the timed interval.
It records the environment, median, and individual samples as JSON. Run again with
`--trace` to count SQL executions, transactions, projection state reads and barriers.
Each sample owns a PostgreSQL schema that is removed on completion.

To compare the introduction of synchronous projections, use `0cc3682d` as the baseline
and `9a8c4dbe` as the first revision with synchronous modes. Run the same benchmark
source against both checkouts and the current tree. Keep dependencies and server settings
the same across runs. The commands below assume Composer dependencies are installed in
the current tree and PostgreSQL is available through `ULTRA_TEST_POSTGRES_DSN` or the
default test DSN from [development verification](../development.md#tests).

```bash
comparison=$(mktemp -d)
for revision in 0cc3682d 9a8c4dbe; do
    git worktree add --detach "$comparison/$revision" "$revision"
    cp -R vendor "$comparison/$revision/vendor"
    composer dump-autoload --working-dir="$comparison/$revision" --no-scripts
done
benchmark=src/Projection/Tests/Benchmark/async.php
php -d opcache.enable_cli=0 "$benchmark" --item-stock=direct --checkout="$comparison/0cc3682d"
php -d opcache.enable_cli=0 "$benchmark" --item-stock=direct --checkout="$comparison/9a8c4dbe"
php -d opcache.enable_cli=0 "$benchmark" --item-stock=direct
```

To compare the item projection's persistence on the same checkout with 128-event batches, run both variants:

```bash
for item_stock in direct mapped; do
    php -d opcache.enable_cli=0 "$benchmark" --event-store=hstore --phase=worker --worker-batches=128 --item-stock="$item_stock"
done
```

Use `--event-store=hstore` for the HStore implementation. Use `--phase=append` or
`--phase=worker` to measure one phase, or `--help` for dataset and batch options.
Reverse the order on a second pass when dispersion is large relative to the difference.

## Guarantee suites

All projection suites live in [src/Projection/Tests](../../src/Projection/Tests), including Postgres and Symfony integration. Memory and Postgres share scenarios where their checkpoint and segment behavior can diverge. See the shared [test-refactoring convention](../development.md#refactoring-tests).

| Guarantee | Suite |
| --- | --- |
| Direct/buffered synchronous appends, private persistence, append conditions and rollback | [SynchronousExecutionTest](../../src/Projection/Tests/SynchronousExecutionTest.php) |
| Reconstruction, generation reset, activation and worker fencing | [ManagedActivationTest](../../src/Projection/Tests/ManagedActivationTest.php) |
| Activation barriers, concurrent writers and late committed lower sequences | [ActivationConcurrencyTest](../../src/Projection/Tests/ActivationConcurrencyTest.php) |
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
| Catalog lookup, Symfony discovery, application activation/scaling overrides and activity-enabled append | [ProjectionRegistrationTest](../../src/Projection/Tests/ProjectionRegistrationTest.php) |
| Delivery of coordinator wakeups and durable worker claims | [ActivationDeliveryTest](../../src/Projection/Tests/ActivationDeliveryTest.php) |
| Segment listing, process backfill and reset commands | [OperatorCommandsTest](../../src/Projection/Tests/OperatorCommandsTest.php) |

Run the fast suites with `bin/phpunit src/Projection/Tests`; use `--group slow` for the Postgres process scenarios. The process harness remains in [Tests/Postgres/Harness](../../src/Projection/Tests/Postgres/Harness) for direct console use. The event-store and generic coordination contracts remain owned by their contexts; Projection verifies their composition. Lease expiry and takeover belong to [Coordination’s ownership suite](../../src/Coordination/Tests/LeaseOwnershipTest.php).
