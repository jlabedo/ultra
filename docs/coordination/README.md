# Ultra\Coordination

This is an experimental, independently usable building block. Its current scope
is the three primitives below. The original intent was to reuse part of the
event-sourced projection execution machinery for other background tasks, such as
cache refreshes. The current module is smaller than that ambition, and its name
is provisional. Resuming the compliance example's cache-refresh experiment from
another branch is tracked in [TODO](../../TODO.md).

Generic primitives for coordinating background work: knowing *what* is pending,
ensuring only *one* worker drives a job at a time, and *waking* that worker
without a thundering herd. These were extracted from the projection runtime once
a second consumer appeared; nothing here knows about projections.

## Primitives

### `Tracking\DirtyKeyTracker`

A coalesced, lost-update-safe ledger of pending work. Producers record coarse
markers as `(channel, key)` pairs; a per-pair counter absorbs repeats. A consumer
reads the pending keys, processes them against current state, then `drainSeen()`s
exactly the counters it observed.

- `channel` is the coarse partition that selects *how* work is processed; `key`
  is the fine-grained dirty unit within it.
- The counter is the basis of the lost-update-safe drain: `drainSeen()` subtracts
  only the observed value, so markers bumped concurrently during processing
  survive to the next cycle. Re-processing a key is therefore harmless.
- `drainSeen()` returns the number of keys whose positive live counter was
  reduced by a positive observed counter. Missing keys, already drained keys
  and non-positive observations do not count; repeating an observation after
  its counter reaches zero returns `0` in both implementations.
- Row count is bounded by the number of *distinct dirty keys*, not by event
  volume.

```php
$tracker->bump('orders', $orderId);          // producer
foreach ($tracker->pendingForChannel('orders') as $k) { /* recompute */ }
$tracker->drainSeen('orders', [$orderId => $seenCounter]);
```

Implementations: `InMemory\InMemoryDirtyKeyTracker`,
`Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker`.

### `Lease\CoordinatorLease`

A singleton-worker lease with a debounced wake signal. At most one worker holds
the lease for a given coordinator key; the lease expires unless renewed, so a
dead holder is eventually superseded. `markWakeEnqueued()` returns true at most
once per TTL window (and never while a coordinator is already live), so many
"work arrived" notifications collapse into a single wake.

The coordinator key lets independent subsystems each run their own singleton.

Implementations: `InMemory\InMemoryCoordinatorLease`,
`Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLease`.

The Postgres implementation creates a missing coordinator row before reading
it under a row lock, within the same transaction. Concurrent callers wait for
the first reservation to commit, then evaluate its current state. Exclusive
ownership, wake debounce and live-holder suppression therefore also apply to
the first reservation on a previously absent key.

### `Lease\Activator`

The minimal "work is available" signal. How a worker is actually woken (enqueue a
message, ping a loop, no-op in tests) is the implementation's concern; subsystems
that must target a specific worker extend this with their own methods.

## Non-goals

This is **not an outbox**. The dirty-key tracker carries no ordering and no
payload — only "this key is dirty." If you need ordered or payloaded delivery,
use a different primitive; do not grow one onto this.

## Wiring note

A framework bundle that consumes these should register its tracker and lease
under **its own private service ids**, not under the `DirtyKeyTracker` /
`CoordinatorLease` interface ids. Binding the interface id globally would clash
with any application that wires its own coordination services for an unrelated
job. (The projection runtime follows this: see
`ProjectionExtension::DIRTY_KEY_TRACKER_ID` / `COORDINATOR_LEASE_ID`.)

## Guarantee suites

Runtime and Postgres guarantees live together under
[`src/Coordination/Tests`](../../src/Coordination/Tests). Common scenarios run
against both implementations through a backend provider; setup and cleanup stay
in the context's support fixture, with assertions in the suites.

| Guarantee | Suite |
| --- | --- |
| Coalesced observations, busiest keys, limits, channel isolation, lost-update-safe acknowledgements and reduced-key counts | [PendingWorkTest](../../src/Coordination/Tests/PendingWorkTest.php) |
| Exclusive ownership, holder-only release and renewal, independent keys and expiration | [LeaseOwnershipTest](../../src/Coordination/Tests/LeaseOwnershipTest.php) |
| Wake debounce, live-holder suppression, expiry and recorded work signals | [WakeSignalsTest](../../src/Coordination/Tests/WakeSignalsTest.php) |
| Postgres contention on absent and existing keys, exclusive lease and wake reservations, live-holder suppression, transaction visibility and rollback | [LeasePersistenceTest](../../src/Coordination/Tests/LeasePersistenceTest.php) |

The persistence suite's contention cases use a separate worker process and
connection, and belong to the `slow` group. They observe the competing request
waiting on a database lock before allowing the first transaction to commit.
Projection keeps its own composition scenarios for bucket activity, scheduling
and worker activation. General audit and deletion rules are in
[Refactoring tests](../development.md#refactoring-tests).
