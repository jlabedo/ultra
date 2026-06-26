# Ultra Signal Module — Specification and Implementation Plan

## 1. Purpose

Add a new Ultra module that provides a PostgreSQL-backed, durable, coalescing wake-up mechanism for asynchronous domain-owned work.

The module should not be implemented as a classic job queue.

A classic queue usually means:

```text
one message = one unit of work
```

The Ultra Signal module means:

```text
many duplicate signals = one stale area = one domain-owned processing pass
```

The signal row is not the work. It is only a durable hint that some domain-owned work may be stale.

Typical use cases:

- projection wakeups;
- async read model refresh;
- search indexing wakeups;
- cache invalidation;
- billing recomputation;
- webhook/outbox nudges;
- materialized view refresh;
- tenant/customer/entity reprocessing.

The main goal is to allow cheap write-side signaling without coalescing on writes, while letting the read side coalesce many granular signals into larger domain-specific units of work.

---

## 2. Non-goals

This module is not intended to be:

- a general-purpose job queue;
- a Kafka/Redis Streams/SQS replacement;
- a consumer-group stream implementation;
- a priority queue;
- a delayed-job framework;
- a retry/DLQ framework;
- the owner of projection segment leases;
- the owner of domain-specific concurrency control;
- a generic work scheduler that understands every handler topology.

The module should stay deliberately small.

Domain-specific components must keep their own correctness leases. For example, the projection context already owns projection segment leases and topology changes. The Signal module only wakes it up and provides safe cleanup of obsolete hints.

---

## 3. Vocabulary

### Signal

A durable, duplicate-tolerant row saying:

```text
Something under this queue/bucket/key may need work.
```

A signal is cheap to append. Writers should not coalesce signals on the write side.

### Signal hint

A signal row claimed by a poller for short-lived delivery to a handler.

Claiming a signal hint is not a correctness lock. It only prevents several pollers from dispatching the exact same signal row at the same time.

### Bucket

An optional numeric operational coordinate.

For partitioned handlers, the bucket allows efficient range-based coalescing.

For non-partitioned handlers, the bucket should simply be `0`, and the handler can use the canonical range `[0,1)`.

### Bucket range

A PostgreSQL `int4range` representing a half-open bucket range, for example:

```text
[512,1024)
```

The range is used by handlers to ack or suppress signals in a covered area.

### Visibility snapshot

A PostgreSQL MVCC snapshot captured by the handler after acquiring its real domain lease.

It is used to ack only signals that were transactionally visible at the time the domain work was claimed.

### Dispatch suppression

An advisory, TTL-based range in the signal store saying:

```text
Do not dispatch signal hints in this bucket range right now.
A domain worker is already processing related work.
```

Suppression is only a performance optimization. It must never be used as a correctness lock and must never decide what can be acked.

### Domain lease

The real concurrency lock owned by the handler/domain component.

Examples:

- projection segment lease;
- search shard lease;
- billing customer-range lease;
- global cache invalidation lease.

The Signal module must not replace this.

---

## 4. Core invariants

The following invariants are mandatory.

### 4.1 Signal rows are hints, not work

A handler must be allowed to receive duplicate hints.

A signal row being delivered to a worker must not imply that the worker is allowed to process the underlying domain work.

### 4.2 Domain lease is the correctness boundary

A handler must acquire its own domain lease before doing expensive or state-mutating work.

For projections, this means the projection handler must acquire the current active segment lease using the current topology.

### 4.3 The poller must not propose or own domain leases

The generic poller should only claim and route signal hints.

It must not ask the handler to pre-compute a lease request and then acquire it later, because the topology may change between proposal and acquisition.

Unsafe pattern:

```text
poller sees bucket 657
handler proposes [512,1024)
topology merges into [0,1024)
poller acquires stale [512,1024)
```

Safe pattern:

```text
poller sees bucket 657
poller sends hint to handler
handler reads current topology and acquires the current domain lease atomically
```

### 4.4 Ack must be visibility-safe

Ack must remove only the signals that were:

1. in the acked queue;
2. in the acked bucket range;
3. visible according to the captured PostgreSQL MVCC snapshot;
4. scheduled as visible no later than the captured visibility time.

Ack must not delete signals inserted by transactions that were not visible when the handler acquired its domain lease.

Ack must not delete delayed signals whose `visible_at` was after the captured visibility time.

### 4.5 Dispatch suppression is advisory

A suppression range may reduce duplicate dispatches, but failure to create it must not affect correctness.

Overlapping suppression ranges should be allowed.

### 4.6 Duplicate delivery is acceptable

The system should allow:

```text
duplicate signal delivery
duplicate domain lease attempts
```

The system must prevent:

```text
duplicate expensive domain processing for the same domain work unit
```

That prevention belongs to the domain lease.

---

## 5. PostgreSQL schema

Use a dedicated schema/table prefix according to Ultra conventions. Names below are proposed and can be adapted.

### 5.1 Required extension

```sql
create extension if not exists btree_gist;
```

This extension is useful for future range-related indexes and constraints. The Signal module itself should not require a uniqueness/exclusion constraint for suppressions.

### 5.2 Signal table

```sql
create table ultra_signals (
    id bigserial primary key,

    queue_name text not null,

    -- Optional operational coordinate.
    -- Non-partitioned handlers should always use bucket = 0.
    bucket int not null default 0,

    -- Semantic identifier, useful for diagnostics and handler-specific logic.
    -- Examples: "projection_a:657", "product:456", "tenant:42".
    signal_key text null,

    -- Top-level transaction id that inserted the signal.
    -- Use xid8, not the internal 32-bit xmin system column.
    insert_xid xid8 not null default pg_current_xact_id(),

    payload jsonb null,

    -- Scheduling visibility. Usually now(), but allows delayed hints if needed.
    visible_at timestamptz not null default now(),

    -- Short-lived delivery lease used only by generic pollers.
    delivery_owner text null,
    delivery_until timestamptz null,

    created_at timestamptz not null default now()
);
```

Notes:

- `insert_xid` must be an explicit column, not `xmin`.
- Use `pg_current_xact_id()` so subtransactions still store the top-level transaction id.
- Writers should append rows and should not update existing signal rows to coalesce.

### 5.3 Signal indexes

Start with simple btree indexes.

```sql
create index ultra_signals_claim_idx
on ultra_signals (queue_name, visible_at, id);

create index ultra_signals_delivery_idx
on ultra_signals (delivery_until);

create index ultra_signals_ack_idx
on ultra_signals (queue_name, bucket, id);

create index ultra_signals_key_idx
on ultra_signals (queue_name, signal_key);
```

The ack query should be written as a numeric range predicate on `bucket` to use `ultra_signals_ack_idx` efficiently:

```sql
bucket >= lower(:bucket_range)
and bucket < upper(:bucket_range)
```

rather than relying only on range operators.

### 5.4 Dispatch suppression table

```sql
create table ultra_signal_suppressions (
    id bigserial primary key,

    queue_name text not null,
    bucket_range int4range not null,

    owner text not null,

    -- Optional domain reference for diagnostics.
    -- Examples: projection segment id, lease id, worker id.
    domain_lease_ref text null,

    suppress_until timestamptz not null,

    created_at timestamptz not null default now()
);
```

Indexes:

```sql
create index ultra_signal_suppressions_range_idx
on ultra_signal_suppressions
using gist (queue_name, bucket_range);

create index ultra_signal_suppressions_until_idx
on ultra_signal_suppressions (suppress_until);
```

Do not add an exclusion constraint. Overlapping suppressions are allowed and harmless.

---

## 6. Public API proposal

Use the names and namespaces that fit Ultra conventions. The following is intentionally PHP-oriented.

### 6.1 Value objects

```php
namespace Ultra\Signal;

final readonly class BucketRange
{
    public function __construct(
        public int $lowerInclusive,
        public int $upperExclusive,
    ) {}

    public static function single(): self
    {
        return new self(0, 1);
    }

    public static function fromTo(int $lowerInclusive, int $upperExclusive): self
    {
        return new self($lowerInclusive, $upperExclusive);
    }

    public function contains(int $bucket): bool
    {
        return $bucket >= $this->lowerInclusive
            && $bucket < $this->upperExclusive;
    }
}
```

```php
final readonly class VisibilitySnapshot
{
    public function __construct(
        /** PostgreSQL pg_snapshot textual representation. */
        public string $snapshot,

        /** Timestamp captured at the same time as the snapshot. */
        public \DateTimeImmutable $visibleAt,
    ) {}
}
```

```php
final readonly class SignalHint
{
    public function __construct(
        public int|string $id,
        public string $queueName,
        public int $bucket,
        public ?string $signalKey,
        public mixed $payload,
    ) {}
}
```

```php
final readonly class SuppressionHandle
{
    public function __construct(
        public int|string $id,
        public string $queueName,
        public BucketRange $bucketRange,
        public string $owner,
        public \DateTimeImmutable $suppressUntil,
    ) {}
}
```

### 6.2 Signal store interface

```php
namespace Ultra\Signal;

interface SignalStore
{
    public function signal(
        string $queueName,
        int $bucket = 0,
        ?string $signalKey = null,
        mixed $payload = null,
        ?\DateTimeImmutable $visibleAt = null,
    ): void;

    /**
     * Claims signal hints for short-lived delivery to handlers.
     *
     * This is not a domain lease.
     *
     * @param list<string> $queueNames
     * @return list<SignalHint>
     */
    public function claimHints(
        array $queueNames,
        string $owner,
        int $limit,
        \DateInterval $deliveryTimeout,
    ): array;

    /**
     * Capture the MVCC visibility horizon used later for ack.
     *
     * Handlers should usually call this immediately after acquiring their
     * real domain lease and store it with that lease or work token.
     */
    public function captureVisibilitySnapshot(): VisibilitySnapshot;

    /**
     * Delete obsolete signals in a queue/range that were visible in the snapshot.
     *
     * The caller must already own the relevant domain lease.
     */
    public function ackVisibleInSnapshot(
        string $queueName,
        BucketRange $bucketRange,
        VisibilitySnapshot $snapshot,
        ?int $limit = null,
    ): int;

    /**
     * Add an advisory suppression range so pollers stop dispatching hints
     * already covered by a domain worker.
     *
     * Failure to suppress must be treated as non-fatal by callers.
     */
    public function suppressDispatch(
        string $queueName,
        BucketRange $bucketRange,
        string $owner,
        \DateTimeImmutable $suppressUntil,
        ?string $domainLeaseRef = null,
    ): SuppressionHandle;

    public function extendSuppression(
        SuppressionHandle $handle,
        \DateTimeImmutable $suppressUntil,
    ): void;

    public function releaseSuppression(SuppressionHandle $handle): void;

    public function cleanupExpired(): void;
}
```

### 6.3 Signal handler interface

The handler must own domain leasing and ack timing.

```php
namespace Ultra\Signal;

interface SignalHandler
{
    public function queueName(): string;

    /**
     * @param list<SignalHint> $hints
     */
    public function handleSignalHints(array $hints): void;
}
```

The poller should not expose a `proposeLease()` API. That would reintroduce stale topology races.

### 6.4 Signal poller

```php
namespace Ultra\Signal;

final class SignalPoller
{
    /**
     * Claim hints from registered queues and route them to their handlers.
     */
    public function pollOnce(): void;
}
```

Responsibilities:

- know registered handlers;
- claim hints from the signal store;
- group hints by queue name;
- route each batch to the right handler;
- not acquire domain leases;
- not ack signals directly;
- not understand projection topology or other handler-specific topology.

---

## 7. Main algorithms

### 7.1 Append signal

```sql
insert into ultra_signals (
    queue_name,
    bucket,
    signal_key,
    payload,
    visible_at
) values (
    :queue_name,
    :bucket,
    :signal_key,
    :payload::jsonb,
    coalesce(:visible_at, now())
);
```

Do not upsert. Do not coalesce on the write side.

### 7.2 Claim signal hints

The generic poller claims rows with a short delivery timeout.

```sql
with candidates as (
    select s.id
    from ultra_signals s
    where s.queue_name = any(:queue_names)
      and s.visible_at <= now()
      and (s.delivery_until is null or s.delivery_until <= now())
      and not exists (
          select 1
          from ultra_signal_suppressions sup
          where sup.queue_name = s.queue_name
            and sup.suppress_until > now()
            and sup.bucket_range @> s.bucket
      )
    order by s.id
    for update skip locked
    limit :limit
)
update ultra_signals s
set
    delivery_owner = :owner,
    delivery_until = now() + :delivery_timeout::interval
from candidates c
where s.id = c.id
returning
    s.id,
    s.queue_name,
    s.bucket,
    s.signal_key,
    s.payload;
```

This only claims delivery of the hint.

It does not mean the worker owns the domain work.

### 7.3 Capture visibility snapshot

Capture both the MVCC snapshot and the scheduling time.

```sql
select
    pg_current_snapshot()::text as snapshot,
    now() as visible_at;
```

The `visible_at` timestamp must be stored with the snapshot.

This is necessary to avoid acking delayed signals that were inserted before the snapshot but scheduled to become visible only after the claim.

### 7.4 Ack visible signals

Ack should delete signals in the range that were visible according to both:

- PostgreSQL transaction visibility;
- signal scheduling visibility.

```sql
with doomed as (
    select id
    from ultra_signals s
    where s.queue_name = :queue_name
      and s.bucket >= :bucket_lower
      and s.bucket < :bucket_upper
      and s.visible_at <= :snapshot_visible_at
      and pg_visible_in_snapshot(
          s.insert_xid,
          :snapshot::pg_snapshot
      )
    order by s.id
    limit coalesce(:limit, 2147483647)
)
delete from ultra_signals s
using doomed d
where s.id = d.id;
```

Return the deleted row count.

For small ranges, `limit` may be null. For large hot ranges, callers should ack in batches.

### 7.5 Suppress dispatch after domain lease acquisition

Handlers may call this after acquiring the real domain lease.

```sql
insert into ultra_signal_suppressions (
    queue_name,
    bucket_range,
    owner,
    domain_lease_ref,
    suppress_until
) values (
    :queue_name,
    int4range(:bucket_lower, :bucket_upper, '[)'),
    :owner,
    :domain_lease_ref,
    :suppress_until
)
returning id;
```

Suppression creation should be best-effort.

If insertion fails, the handler should continue processing. The worst outcome is extra poller noise.

### 7.6 Cleanup expired rows

```sql
delete from ultra_signal_suppressions
where suppress_until <= now();
```

Optionally clear expired delivery owners:

```sql
update ultra_signals
set delivery_owner = null,
    delivery_until = null
where delivery_until <= now()
  and delivery_owner is not null;
```

This second cleanup is not required for correctness because the claim query already treats expired delivery leases as available.

---

## 8. Handler contract

A handler receiving hints must follow this general pattern.

```text
1. Receive one or many SignalHint objects.
2. Deduplicate hints according to current domain topology.
3. For each current domain work unit:
   a. acquire the real domain lease atomically;
   b. capture a VisibilitySnapshot;
   c. optionally insert a DispatchSuppression covering the domain work unit;
   d. process the domain work;
   e. while still owning the domain lease, ack visible signals covered by the work unit;
   f. release suppression;
   g. release domain lease.
```

Important:

- The handler must recompute the current work unit at acquisition time.
- The handler must not rely on a range proposed earlier by the poller.
- The handler must not ack after losing or expiring the domain lease.
- The handler may receive several hints that map to the same domain work unit.
- The handler should deduplicate within the batch.

---

## 9. Projection integration example

Assume projection `projection_a` has 1024 possible buckets and dynamic active segments.

Example signals:

```text
projection_a / bucket 657
projection_a / bucket 658
projection_a / bucket 900
```

Current active topology:

```text
[512,1024)
```

The projection handler should:

1. receive a batch of signal hints;
2. group them by current active segment;
3. attempt to acquire each current segment lease once;
4. for each acquired segment:
   - capture `VisibilitySnapshot`;
   - store it with the projection lease or local processing token;
   - suppress dispatch for `[512,1024)` until the segment lease expires;
   - process the projection segment;
   - ack signals for `projection_a` in `[512,1024)` visible in the snapshot;
   - release suppression and projection lease.

Pseudo-code:

```php
final class ProjectionSignalHandler implements SignalHandler
{
    public function queueName(): string
    {
        return 'projection_a';
    }

    public function handleSignalHints(array $hints): void
    {
        $buckets = array_map(fn (SignalHint $hint) => $hint->bucket, $hints);

        $segments = $this->projectionRuntime->currentSegmentsCovering($buckets);

        foreach ($segments as $segment) {
            $lease = $this->projectionRuntime->tryAcquireSegmentLease($segment);

            if ($lease === null) {
                continue;
            }

            $snapshot = $this->signalStore->captureVisibilitySnapshot();

            $suppression = null;

            try {
                $suppression = $this->trySuppress($lease, $snapshot);

                $this->projectionRuntime->processSegment($lease);

                // Must be called only while the projection lease is still owned.
                $this->signalStore->ackVisibleInSnapshot(
                    queueName: 'projection_a',
                    bucketRange: $lease->bucketRange,
                    snapshot: $snapshot,
                );
            } finally {
                if ($suppression !== null) {
                    $this->signalStore->releaseSuppression($suppression);
                }

                $this->projectionRuntime->releaseSegmentLease($lease);
            }
        }
    }
}
```

The actual projection implementation should ensure that `tryAcquireSegmentLease()` reads current topology and acquires the segment in one critical section.

This prevents stale topology bugs such as:

```text
signal bucket 657 maps to [512,1024)
then topology merges into [0,1024)
then stale [512,1024) is acquired
```

The handler must not allow that.

---

## 10. Behavior with many signals and many workers

Consider many signals in the same active segment:

```text
projection_a:657
projection_a:658
projection_a:659
projection_a:700
projection_a:901
```

And many workers polling concurrently.

Several workers may claim different signal rows:

```text
worker-1 claims 657
worker-2 claims 658
worker-3 claims 700
worker-4 claims 901
```

They all route to the projection handler.

The handler maps them to the same current segment:

```text
[512,1024)
```

Only one worker acquires the projection segment lease. The others fail fast and return.

Therefore there may be duplicate control-plane attempts, but not duplicate expensive processing.

Cheap duplicated work:

```text
claim hint
route to handler
read current topology
try domain lease
fail fast
```

Expensive protected work:

```text
read event store
update projection state
write projection rows
ack covered signals
```

The expensive work runs once because it is protected by the domain lease.

Once the winning worker inserts a dispatch suppression range, generic pollers stop claiming most remaining hints in the covered range. This reduces noise while keeping correctness domain-owned.

This trade-off is intentional:

```text
Duplicate signal delivery is allowed.
Duplicate lease attempts are allowed.
Duplicate expensive domain processing is not allowed.
```

---

## 11. Concurrency scenarios

### 11.1 Same signal, multiple pollers

Two pollers try to claim the same signal row.

Expected behavior:

- `for update skip locked` ensures only one poller updates the row;
- the other skips it;
- exact same signal row is not dispatched twice during its delivery timeout.

### 11.2 Different signals, same domain segment

Several pollers claim different signal rows in the same domain segment.

Expected behavior:

- several handlers may attempt the same domain lease;
- one wins;
- losers fail fast;
- only the winner processes.

### 11.3 Domain lease acquired, suppression insert fails

Expected behavior:

- handler continues processing;
- correctness is unaffected;
- there may be extra poller noise.

### 11.4 Poller claims signal before suppression is inserted

Expected behavior:

- already-claimed hints may still be dispatched;
- handler tries domain lease and fails if another worker owns it;
- no duplicate expensive work.

### 11.5 Worker crashes after suppression

Expected behavior:

- domain lease expires according to domain rules;
- suppression expires by TTL;
- signals become claimable again;
- no manual cleanup is required for correctness.

### 11.6 Signal inserted by transaction still in progress at snapshot time

Expected behavior:

- signal has `insert_xid` from its inserting transaction;
- `pg_visible_in_snapshot(insert_xid, snapshot)` is false;
- ack does not delete it;
- once committed, it remains for a later processing pass.

### 11.7 Signal inserted after snapshot

Expected behavior:

- transaction id is not visible in the snapshot;
- ack does not delete it;
- it remains for a later pass.

### 11.8 Delayed signal inserted before snapshot but visible later

Example:

```text
T1 delayed signal inserted with visible_at = T3
T2 handler captures snapshot
T3 signal becomes scheduled-visible
T4 handler acks
```

Expected behavior:

- ack must not delete the delayed signal;
- this is why ack checks `visible_at <= snapshot.visibleAt`;
- `pg_visible_in_snapshot()` alone is not sufficient.

### 11.9 Handler loses domain lease before ack

Expected behavior:

- handler must not ack;
- this must be enforced by the handler/domain component;
- SignalStore cannot generally verify external domain lease ownership.

---

## 12. Poller fairness

A single global query ordered by `id` can let a hot queue dominate.

Recommended v1 approach:

- the poller should know registered handlers;
- loop through handlers/queue names in round-robin order;
- claim a small batch per queue;
- route each batch to its handler.

Example:

```php
foreach ($registry->handlersRoundRobin() as $handler) {
    $hints = $signalStore->claimHints(
        queueNames: [$handler->queueName()],
        owner: $workerId,
        limit: $perQueueLimit,
        deliveryTimeout: new DateInterval('PT5S'),
    );

    if ($hints !== []) {
        $handler->handleSignalHints($hints);
    }
}
```

This keeps the poller generic while avoiding starvation.

---

## 13. Performance expectations

### Write side

Signal writes are append-only inserts.

No unique constraint, no upsert, no hot-row contention.

This is the main performance win.

### Polling side

Pollers claim rows with `for update skip locked` and a short delivery timeout.

This prevents every worker from dispatching the exact same row.

### Domain side

Real duplicate work is prevented by domain leases.

Multiple workers may attempt to acquire the same domain lease, but failed attempts are cheap.

### Ack side

Ack is a range delete using:

```text
queue_name
bucket range
snapshot visibility
visible_at cutoff
```

For large ranges, ack should support batching via `limit`.

### Table bloat

Since the module deletes rows, PostgreSQL bloat is expected under high churn.

Mitigations:

- batch ack;
- frequent autovacuum tuning;
- optional periodic cleanup job;
- optional partitioning by `created_at` or `queue_name` later;
- avoid making the signal table the durable history source.

The signal table is not an audit log.

---

## 14. Observability

Add metrics/logs for:

- signals appended per queue;
- hints claimed per queue;
- empty polls;
- active suppressions;
- suppressed hints skipped, if easy to measure;
- acked rows per queue/range;
- failed handler executions;
- handler lease acquisition failures, reported by handler/domain module;
- signal age: oldest `created_at` per queue;
- signal backlog count per queue;
- delivery timeout expirations or reclaims.

Useful SQL diagnostics:

```sql
select queue_name, count(*) as pending
from ultra_signals
where visible_at <= now()
group by queue_name;
```

```sql
select queue_name, min(created_at) as oldest_signal
from ultra_signals
group by queue_name;
```

```sql
select queue_name, count(*) as active_suppressions
from ultra_signal_suppressions
where suppress_until > now()
group by queue_name;
```

---

## 15. Implementation plan

### Phase 1 — Database migration

Implement migrations for:

1. `ultra_signals`;
2. `ultra_signal_suppressions`;
3. indexes;
4. `btree_gist` extension if allowed by Ultra migration conventions.

If extension creation is not allowed in app migrations, document it as an installation prerequisite.

### Phase 2 — Core value objects

Implement:

- `BucketRange`;
- `VisibilitySnapshot`;
- `SignalHint`;
- `SuppressionHandle`.

Add validation:

- bucket range lower must be `<` upper;
- non-partitioned range should be represented by `[0,1)`;
- bucket must be integer;
- queue name must be non-empty.

### Phase 3 — PostgreSQL SignalStore

Implement `PostgresSignalStore` with Doctrine DBAL or Ultra's existing DB abstraction.

Methods:

- `signal()`;
- `claimHints()`;
- `captureVisibilitySnapshot()`;
- `ackVisibleInSnapshot()`;
- `suppressDispatch()`;
- `extendSuppression()`;
- `releaseSuppression()`;
- `cleanupExpired()`.

Important implementation detail:

- PostgreSQL `pg_snapshot` can be represented as text in PHP and passed back as `:snapshot::pg_snapshot`.
- `xid8` values should stay in PostgreSQL; PHP does not need to interpret them.

### Phase 4 — Generic poller

Implement `SignalPoller`:

- registry of handlers;
- worker id provider;
- per-queue or round-robin polling;
- batch claim;
- group hints by queue;
- route to `SignalHandler::handleSignalHints()`.

The poller must not:

- acquire domain leases;
- ack signals;
- propose bucket ranges;
- understand projection topology.

### Phase 5 — Projection integration

Integrate with projection context as first real consumer.

Projection handler should:

1. receive signal hints;
2. deduplicate by current active segment;
3. acquire projection segment leases using current topology;
4. capture visibility snapshot after segment lease acquisition;
5. optionally suppress dispatch for the segment range;
6. process the segment;
7. ack visible signals for the segment range while still owning the segment lease;
8. release suppression and segment lease.

Do not move projection lease ownership into the Signal module.

### Phase 6 — Tests

Add unit/integration tests listed in section 16.

Concurrency tests should use separate database connections where needed.

### Phase 7 — Hardening

Add:

- metrics;
- cleanup command/job;
- configurable poll batch size;
- configurable delivery timeout;
- configurable per-queue fairness strategy;
- optional ack batch size;
- logs around suppression failures.

---

## 16. Test plan

### 16.1 Append tests

- `signal()` inserts a row.
- `bucket` defaults to `0`.
- duplicate signals are allowed.
- payload is stored as JSON.
- `insert_xid` is not null.

### 16.2 Claim tests

- visible signal is claimed.
- future `visible_at` signal is not claimed.
- claimed signal is not claimed again before `delivery_until`.
- expired delivery lease makes signal claimable again.
- two concurrent claimers do not receive the same row.
- batch claim returns at most `limit` rows.

### 16.3 Suppression tests

- signal covered by active suppression is not claimed.
- signal outside suppression is claimed.
- expired suppression no longer blocks claiming.
- overlapping suppressions are allowed.
- release suppression makes covered signals claimable again.

### 16.4 Snapshot ack tests

Use multiple DB connections.

Test 1: deletes visible signal.

```text
T1 insert signal and commit
T2 capture snapshot
T3 ack with snapshot
expected: signal deleted
```

Test 2: does not delete signal inserted after snapshot.

```text
T1 capture snapshot
T2 insert signal and commit
T3 ack with snapshot
expected: signal remains
```

Test 3: does not delete signal from transaction in progress at snapshot.

```text
conn1 begin
conn1 insert signal
conn2 capture snapshot
conn1 commit
conn2 ack with snapshot
expected: signal remains
```

Test 4: does not delete delayed signal that was inserted before snapshot but not scheduled-visible at snapshot time.

```text
T1 insert signal with visible_at = future
T2 capture snapshot before visible_at
T3 wait until visible_at has passed
T4 ack with snapshot
expected: signal remains
```

Test 5: deletes only covered bucket range.

```text
insert buckets 100, 200, 900
ack range [0,512)
expected: 100 and 200 deleted, 900 remains
```

### 16.5 Poller tests

- poller routes claimed hints to correct handler.
- poller does not call unknown queue handlers.
- poller groups hints by queue.
- poller does not ack directly.
- handler exception does not corrupt signals; delivery timeout eventually makes hints claimable again.

### 16.6 Many signals / many workers integration test

Create many signals in the same logical segment.

Use a fake handler with a fake domain lease table.

Expected:

- many hints may be claimed;
- only one fake domain lease is acquired;
- expensive processing counter increments once;
- losers fail fast;
- ack deletes all visible covered signals.

### 16.7 Projection topology race test

This belongs mostly in projection integration tests.

Scenario:

```text
signal bucket 657 exists
old topology has [512,1024)
topology changes to [0,1024)
handler receives hint for 657
handler must acquire [0,1024), not stale [512,1024)
```

Expected:

- handler reads current topology at acquisition time;
- stale proposed ranges are not used.

---

## 17. Open questions

These can be decided during implementation.

1. Should the module be named `Ultra\Signal` or `Ultra\Signals`?

   Recommendation: `Ultra\Signal` for namespace, “Ultra Signals” for documentation.

2. Should `SignalStore::signal()` accept string keys only, or typed value objects?

   Recommendation: v1 accepts strings and ints. Typed helpers can be added later.

3. Should `payload` be part of v1?

   Recommendation: yes, but document that it is diagnostic/routing metadata, not the durable source of truth.

4. Should `delivery_owner` be cleared after successful handler call?

   Recommendation: not required. If processing succeeds, ack will delete covered rows. If it fails, timeout is enough.

5. Should suppressions be heartbeated automatically?

   Recommendation: expose `extendSuppression()`, but let handlers decide. Suppression is advisory.

6. Should ack require a domain lease token?

   Recommendation: not in the generic module. The generic module cannot validate arbitrary domain leases. Domain handlers must enforce this.

---

## 18. Final design summary

The Ultra Signal module provides:

```text
append-only durable signals
short-lived generic hint claiming
generic poller routing
MVCC-safe signal ack
optional advisory dispatch suppression ranges
```

It deliberately does not provide:

```text
domain work ownership
domain topology management
exactly-once processing
consumer-group stream semantics
```

The correct mental model is:

```text
SignalStore:
  stores and cleans wake-up hints

SignalPoller:
  routes hints to handlers

SignalHandler:
  owns domain lease, processing, and ack timing

DispatchSuppression:
  reduces poller noise after a domain lease is acquired
```

This makes the module generic, useful, and safe for Ultra while keeping projection-specific concurrency inside the projection context.
