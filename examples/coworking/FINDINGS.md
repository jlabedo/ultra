# Findings: DecisionHandler under a set-based domain

The coworking domain (see [README.md](README.md)) is implemented four times with the same pure rules. The scenario
suite passes on all four flavors. Tests that reproduce an open gap go in the `finding` group
(`vendor/bin/phpunit --group finding`), which the default run excludes; it is empty once every bug below is fixed.

| | Event sourced | State store decisions | State store direct | Classic (Doctrine ORM) |
|---|---|---|---|---|
| Non-blank lines (excluding shared rules) | 807 in 24 files | 717 in 19 files | 367 in 6 files | 364 in 6 files |
| Code lines (excluding comments, blank lines and PHP opening tags) | 726 | 634 | 354 | 342 |
| Scenario suite | passes | passes | passes | passes |
| Concurrent decision races tested | yes, deterministically | yes, deterministically | yes, deterministically | no (locks need two processes) |
| Set-based rules | event folds | materialized schedules and usage | SQL queries replayed at flush | SQL queries inside the transaction |
| Decision transaction | outside | outside | outside | inside |

Counts include every PHP file under each flavor's source directory, including its service, repository, states,
commands and events. They exclude tests, shared domain rules, configuration, DDL and framework implementation.
The direct variant has 157 non-blank service lines, 116 repository lines and 94 state lines; Doctrine has 192,
55 and 117 respectively. These are source-size measurements, not performance measurements. Mapping conventions
and the placement of validation code also affect the result.

Each finding is classified:

- **Feature**: the design holds; the framework is missing something that fits it.
- **Bug**: an implementation defect.
- **Design**: the limitation comes from the model itself; fixing it means changing a core assumption.

## 1. A consistency boundary can depend on another state (Feature; step 1 done)

The quota boundary is `company@month`. The company is known only from the member, and the member is loaded by the
same decision. A decision parameter used to bind only to a property of the command, so:

- `BookRoom` and `RescheduleBooking` required `companyId` in the command. The caller had to read the member first,
  outside any consistency boundary, only to learn which boundary to load, and `decide()` re-verified it
  (`member.company_mismatch`).
- In the state-store flavor, `CancelBooking` and `RescheduleBooking` loaded rows identified by the loaded booking
  by hand, with manual `expect()` calls.

The classic flavor writes `$member->company`.

**Done (step 1, dependent binding):** `#[BindTo(method: 'companyMonthId')]` names a message method whose parameters
are other states of the same decision, matched by name. The aspects resolve the dependency graph at compile time and
load in rounds. In the event-sourced flavor, every round's append condition guards the append, so a member switching
company between load and append is a conflict. In the state store, a dependent state is `null` when a source is
missing. `companyId` and the mismatch checks are gone from the commands.

**Parked (step 2):** composed decision states (for example `MemberMonthlyQuota`, a class whose constructor
parameters are states resolved with the same bindings). With binding by convention, `BookRoom` and
`RescheduleBooking` only repeat two short id methods and the quota check, which a helper covers. Revisit if
decisions start repeating larger groups of related states.

**New limit found:** a signature cannot express a conditional load. State-store `CancelBooking` only needs the
company-month usage when the cancellation is refundable, which depends on the clock. Declaring it as a parameter
would always lock the usage row, and late cancellations would then conflict with concurrent bookings in the same
month. That load stays manual.

## 2. A decision cannot load a runtime number of states (Feature, partially covered)

A weekly series touches one room-day and one company-month per occurrence, and the number of occurrences comes from
the command. Neither flavor can declare that as a parameter list.

Workaround: `BookWeeklyHandler` sends one `BookRoom` per occurrence inside the same unit of work.

- **Event sourced: works.** `EventStoreUnitOfWork` exposes pending events to later loads, keeps append conditions on
  persisted checkpoints, and flushes the whole series in one append. All-or-nothing and quota-across-occurrences
  both pass. This composition is a real strength, but it is not documented anywhere; I found it by reading
  `EventStoreUnitOfWork`.
- **State store: works** since the fix for finding 6.
- Both flavors stop at the first failing occurrence, so a caller cannot get "weeks 3 and 5 are taken" in one answer.

**Direction**: document decision composition within one unit of work as the supported pattern for variable
fan-out. Optionally add collection parameters, such as `list<RoomDayScheduleState>` bound to a command list path,
so one decision can report every violation.

## 3. Event tags are single-valued per dimension (Design; fixed roles addressed by aliases)

`Event::$domainIds` is `array<string, string>`: an event is tagged with at most one value per dimension, and a
decision state is loaded from exactly one tag. A reschedule leaves one room-day and enters another, so a single
domain event could not be seen by both schedules: it was split into `BookingRescheduledFrom` and
`BookingRescheduledTo`. A transfer between two wallets would hit the same limit.

**Done (fixed roles):** an event names each role with its own tag (`roomDay`, `previousRoomDay`), and an entity
declares extra tag names as aliases of its single tag:
`#[EventSourcedEntity(tags: ['roomDay'], aliases: ['previousRoomDay' => 'roomDay'])]`. Events are then also loaded
when the alias equals the entity's id, and the append condition covers the alias branch. The example is back to one
`BookingRescheduled` event; the schedule and usage handlers infer their role from their own state (the booking is
already here and the day changed → it leaves; otherwise it arrives or moves within the day).

Axon Framework 5 takes the other route: tags are a set of key/value pairs, so one event can carry two values for the
same key (an iterable `@EventTag` produces one tag per element), and entities describe what they are sourced from in
code (`@EventCriteriaBuilder`). Its entities know their id, which is what makes role-agnostic set tags workable
there; our event-sourced states do not.

## 4. Derived identities have no first-class place (Feature; Mapper bug fixed)

Boundaries such as `roomDay` and `companyMonth` are computed from command data.

- `#[BindTo]` only accepts a property path, so commands expose derived IDs as PHP property hooks. Hooks cannot be
  `readonly`, so these commands cannot be `readonly class` like the others. The same goes for events that derive
  their tags with hooks (`RoomBooked` and the other booking events).
- **Fixed:** the generated Mapper used to treat a get-only virtual property as data and assign it after
  construction ("Property RoomBooked::$roomDayId is read-only"). The Mapper compiler now ignores virtual
  properties entirely: they are neither normalized nor denormalized, and they don't count toward an `#[AsValue]`
  object's single property.

**Done for commands:** `#[BindTo(method: ...)]` computes an id with a plain method, so commands are `readonly class`
again. Events still derive their tags with hooks, since `#[DomainIdentifier]` only targets properties.

## 5. Check mode could not use services (Bug, fixed)

`EventSourcedDecisionAspect` used to call a separate `check()` method, passing states matched by parameter name
and `null` for everything else. `BookRoom::check()` needed the clock, so it got a `TypeError`.
`StateStoreDecisionAspect` had no check mode at all, and `check()` had to repeat the `decide()` signature.

**Fixed:** check mode (`DecisionMode::CONTEXT_KEY => DecisionMode::CHECK`) now runs `decide()` itself, with the same
states and services. It returns the violations of a thrown `InvariantViolation` (or `[]`) and discards the effects,
in both aspects. `check()` methods and `CommandHasNoCheckException` are gone.

Trade-off: check mode reports what `decide()` throws. A decision that validates in several `throwIfAny` groups only
surfaces the first failing group.

## 6. State store: several decisions could not expect the same row in one unit of work (Bug, fixed)

In the weekly series, the second occurrence loads the company-month usage that the first one already changed.
`StateStore::expect()` used to register the buffered item keys as the expected row-key set, and `MappedRowSet`
rejected it as "Conflicting row-set key expectations" although nothing concurrent happened. Plain rows had the same
problem ("Conflicting expected values", or "already expected to be absent" after an insert).

The root cause was in `MappedRowSet`: `row()`/`rows()` return the unit of work's view (committed data with buffered
changes applied), but expectations were always interpreted against committed data.

**Fixed:** expectations are now written against the unit-of-work view. The part decided by buffered changes is checked
immediately, and the rest becomes a condition checked at flush. Row-key expectations ignore keys whose presence this
unit of work decided. `StateStore` is unchanged. A stale state copy (loaded before a write in the same unit of work)
is now rejected, including through another state class mapping the same row.

**Related bug, fixed:** an expectation on a row the decision did not save was never checked. A booking read the
company's quota without writing the company, so a plan downgrade committed in between went unnoticed (write skew).
`ConcurrentDecisionsTest` now covers it. A row that is only read is flushed as a check-only plan (`SELECT ... FOR
SHARE`). An absent row cannot be locked, so absent checks, inserts and upserts lock the row's key with an advisory
lock. An update that writes only expected values becomes a check too, so saving an unchanged state writes nothing.

## 7. State store: set-based rules require hand-maintained denormalized states (Design, for the decision-state flavor)

`StateStore` loads by id only. "No overlap in this room on this day" and "sum of minutes this month" are
queries in the classic flavor. Here they need extra states that duplicate booking data:

- `RoomDayScheduleState` + `ScheduledSlot` and `CompanyMonthUsageState` + `Charge`, backed by parent tables whose only
  column is the id, since a collection needs an owner row.
- One booking writes three states, and cancel, reschedule and close must keep those copies in sync by hand.
  Nothing detects drift between `cw_ss_bookings` and the schedules.

**Direction**: a query-scoped state, such as the slots of `cw_ss_bookings` where `room_id = ?` and the day matches,
guarded by a row-set expectation over that scope. `MappedRowSet` already has scoped row sets and
`expectSameRowKeys()`, but the scope is limited to key columns.

**Alternative implemented:** the fourth flavor uses StateStore directly with SQL queries and deferred query
expectations. It keeps the same four normalized entities as Doctrine, with no room-day or company-month copies.
This demonstrates that the extra states are a consequence of how this example expresses decision parameters,
not a mandatory storage model imposed by StateStore itself. The query-scoped-state direction remains separate.


## 8. State store: manual loads are unsafe by default (Feature)

Because of finding 1, some state-store decisions load rows themselves. Two problems follow:

- A manual `load()` without `expect()` silently becomes a blind overwrite (lost update). Nothing warns.
- Loading a row that is also a decision parameter (same-day reschedule) creates two instances. Saving both lets the
  last one win. `RescheduleBooking` must branch on "same room-day?" to reuse the parameter instance.

Smaller papercuts in the same flavor:

- A non-nullable state parameter throws `StateNotFoundException` instead of a domain violation, so every parameter
  ends up nullable with manual null checks.
- `?BookingState $booking` is declared in `BookRoom` only to get `expectMissing()`.
- Snake-case column overrides need explicit `#[StateColumn('snake_name')]`; properties with conventional names need no attribute.
- DDL for state tables is written by hand (`CoworkingSchema`).

Dependent binding removed most manual loads (`RescheduleBooking` has none left, and the same-row branch now only
decides which copy to return). What remains is a conditional load (`CancelBooking`) and variable fan-out
(`CloseRoomForDay`).

**Implemented for direct reads:** `StateStore::observe(Class, id)` registers the usual state expectations when a
state exists, or an absence expectation when it does not. Presence is checked even for identifier-only states.
The direct flavor uses it with the same typed `company()` and `booking()` helpers as Doctrine, explicit creation
checks, and bulk closure. `load()` remains available without automatic expectations; decision aspects and manual
callers can adopt observation where appropriate.

## 9. Smaller observations

- Two `ConcurrencyException` classes exist (`Ultra\EventStore\Exception` and `Ultra\UnitOfWork\Exception`). A
  retry-on-conflict policy has to catch both. Nothing retries a decision on conflict today, and a retry advice
  would fit the design naturally.
- The Mapper does not support `Ultra\Time\DatePoint`, the type `ClockInterface::now()` returns (exact class match in
  `DateTimeCodeGeneratorBuilder`). States and events use `\DateTimeImmutable` instead.

## Known limitations addressable by redesign

Accepted for now, but not considered acceptable long term. Each one needs code redesign rather than a small fix.

- **Dependent binding across flavors.** A decision can mix event-sourced and state-store states, but a binding method
  can only depend on states of its own flavor: each aspect loads its states independently, in its own rounds. Crossing
  flavors needs one resolution engine ordering both aspects' loads and sharing loaded states.

- **Multi-valued tags (sets).** Aliases cover fixed roles (old/new day, source/destination wallet), not an event
  touching a variable number of entities of one kind (an order over N products, a bulk cancellation). Supporting
  sets means `Event::$domainIds` holding lists, a `jsonb` (or per-identifier rows) storage instead of `hstore`,
  append-condition locks multiplied across values, projection partitioning for events in several buckets, and state
  identity available in handlers so they can tell their role.

## Where the design wins

The comparison is not one-sided:

- **Concurrency is correct by construction and testable.** The two decision flavors reject a concurrent overlapping
  booking and a concurrent quota drain, and accept unrelated concurrent bookings. `ConcurrentDecisionsTest` proves
  it deterministically, with a second kernel committing between load and flush. The classic flavor guards quota and overlap queries by locking the company and room rows in a fixed order. Forgetting a lock,
  or taking them in a different order, is a silent double-booking or a deadlock, and no single-process test catches
  either.
- **The event-sourced flavor derives what the others store.** Quota usage and room schedules are folds over events,
  with no copies to keep in sync. `CloseRoomForDay` refunds every affected company without loading any usage state:
  the cancellation events are routed to each company-month by their tags. This is the cleanest use case of the
  three implementations.
- **Decisions are pure.** `decide()` receives states and returns effects, so business rules can be unit-tested
  without a database. The classic service needs repositories and a transaction.
- **The classic flavor has its own traps.**
  - Invariant exceptions inside `wrapInTransaction()` close the entity manager, which is why the service uses a
    connection-level transaction.
  - A concurrent duplicate email only fails on the unique index, as a database error.
  - A weekly series must `flush()` between occurrences so the overlap queries see earlier ones.

## 10. Direct StateStore: a closer comparison with Doctrine

`StateStoreDirect/BookingService` exposes the same nine operations as the Doctrine service. It calls
`UnitOfWorkRunner::run()` explicitly; there are no messages, `SelfHandler`, `DecisionHandler`, bindings or decision
aspects in this variant. The runner owns one transaction covering the entire flush. The service prepares its work
outside that transaction, and a weekly series has no intermediate flush.

Both services use typed `company()` and `booking()` helpers to load required objects and report the same domain
violations. Creation checks stay inline in both implementations; the direct variant has no generic `state()` or
`expectNew()` abstraction. The compiled query-guard prototype removes `lockBookingGuards()` and all service-level
manual lock requests. States still carry identifiers rather than Doctrine entity associations.

Company plan changes use only optimistic row expectations: they do not validate a set-based query and need no
manual guard. A booking's observed company quota is checked and protected until commit by the row plan, so a
concurrent plan change is detected or waits for that check. Member registration groups duplicate identifier and
email violations in one validation, as in Doctrine.

The direct variant uses `StateStore::observe(Class, id)` and constructor-injected `QueryGuard` instances declared
with `#[DefineQueryGuard]`. `watch(Booking::class, keyBy: 'company_id')` and `whenColumnsChange()` compile global
write rules; a second guard watches room-booking dependencies. `QueryGuard::observe(value, callback)` queues a
shared guard and records the query result. Flush derives exclusive guards from affected row plans, including old
and new scope values, consolidates modes before acquisition, checks query results, then runs the row-set plans.
Named query guards use a separate advisory namespace from manual row and automatic absent-row locks. Named guards
are ordered by name/value; legacy manual guards follow in table/key order. Rows written by the plan are not all locked up front;
`SERIALIZABLE` is not used. The existing adapter sorts row plans by key and batches consecutive compatible
operations; it does not impose a global operation-type order.

**Advantages:** six source files, a familiar service/repository path, normalized relational data, and one write
per changed booking rather than three synchronized state copies. StateStore's readonly snapshots and optimistic
checks remain available without adopting message dispatch. Runtime fan-out is a plain loop. For an AI, the path
from an operation to its loads, guards and SQL is visible through ordinary method calls; the framework phases are
now documented beside the public APIs. Discoverability still requires reading the runner and the repository's
unit-of-work participation to understand when writes and cleanup happen.

**Costs:** correctness of set-based rules depends on complete dependency declarations. The framework enrolls
affected writes even when no query is called, but SQL writers bypassing the StateStore flush do not participate.
A guard declaration is not an optimistic snapshot expectation;
State fields influencing the decision need the expectations registered by `observe()` or explicit `expect()`. Queries are executed twice, and strict
comparison can reject a concurrent change even when a fresh decision would still succeed. The caller must retry
that decision after `ConcurrencyException`; retry is not added by this change. The transaction is shorter in scope
than Doctrine's, but the extra reads and advisory-lock statements are real work. No throughput or latency claim is
made without a benchmark. Ordered guards address their own acquisition order, not every possible deadlock involving
other database writers or constraints.

**Pending writes are an application concern for manual SQL.** Replayed queries see committed data before this
flush's writes. The repository keeps each pending booking's original and current snapshot, so usage adds the local
charge delta, overlap queries overlay pending slots, and bulk closure sees locally prepared bookings. Only the raw
SQL result becomes the expected result. The repository participates in unit-of-work cleanup while StateStore owns
the write plan. The repository has 116 non-blank lines versus Doctrine's 55, including its guard declarations. It is needed
for several operations composed before flush, not only for weekly series. Tests cover quota exhaustion, cancelling
then rebooking, successive moves, rollback cleanup, and a weekly series spanning months.

**The decision variants retain capabilities that this service does not reproduce:** automatic expectations for
declared states, mediator dispatch and advice, and check mode returning domain violations without saving effects.
Those capabilities should be evaluated on their own value. `SelfHandler` combines the message and its decision in
one place; it also ties business behavior to dispatch conventions. `DecisionHandler` makes state dependencies
visible in signatures and binds consistency checks to them; its cost is translating query-shaped invariants into
state parameters and learning binding/effect semantics. Direct StateStore makes those decisions explicit in service
code, including manual expectations and query-overlay bookkeeping.

Package tests also verify shared reader compatibility, exclusion of writers that never call a query, old/new
scope protection, and exclusive consolidation across mapped views before query validation. Constructor injection
works in a dumped container; unknown columns and conflicting definitions fail compilation. Guards remain held
until transaction completion and use their own namespace. The direct flavor joins the
same deterministic competing-kernel tests as the decision variants: overlap, quota drain, plan downgrade and
unrelated bookings.

This remains an experimental API. It supports non-null scalar scope values on registered row tables, requires
observed scopes for existing-row mutations and rejects blind upserts. It does not derive composite or calendar
scopes or enroll independently flushed row sets and collection-item tables. See
[the prototype notes](../../src/StateStore/QUERY_GUARD_PROTOTYPE.md) for the complete contract and test commands.

## Verdict

The fourth variant changes the comparison: StateStore alone has a similar application footprint to Doctrine for
this domain. The original gap in source size mainly comes from expressing set-based rules as materialized decision
states, plus commands and binding plumbing. It does not establish that StateStore requires that model.

Direct StateStore trades Doctrine's long pessimistic transaction for decisions prepared outside a transaction,
followed by guarded query rechecks and optimistic writes in one flush. Its prototype keeps explicit dependency
declarations, automatically enrolls affected writes, and adds bookkeeping for pending writes. DecisionHandler offers more automatic consistency
checking for its declared boundaries and dispatch/check-mode integration; that benefit should justify the extra
model and ceremony for the application. EventSourcedState remains useful when deriving schedules and quota from
recorded domain facts is itself valuable.

The remaining design work is deciding whether query-backed decision states deserve a first-class interface, and
whether the explicit direct API is sufficient for set-based domains. Multi-valued event tags remain a separate
limitation of the event model.
