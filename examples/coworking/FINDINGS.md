# Findings: DecisionHandler under a set-based domain

The coworking domain (see [README.md](README.md)) is implemented three times with the same pure rules. The scenario
suite passes on all three flavors. Tests that reproduce an open gap go in the `finding` group
(`vendor/bin/phpunit --group finding`), which the default run excludes; it is empty once every bug below is fixed.

| | Event sourced | State store | Classic (Doctrine ORM) |
|---|---|---|---|
| Non-blank lines (excluding shared rules) | 877 in 25 files | 698 in 19 files | 364 in 6 files |
| Scenario suite | passes | passes | passes |
| Concurrent races tested | yes, deterministically | yes, deterministically | no (locks need two processes) |

Each finding is classified:

- **Feature**: the design holds; the framework is missing something that fits it.
- **Bug**: an implementation defect.
- **Design**: the limitation comes from the model itself; fixing it means changing a core assumption.

## 1. A consistency boundary can depend on another state (Feature)

The quota boundary is `company@month`. The company is known only from the member, and the member is loaded by the
same decision. A decision parameter can only bind to a property of the command, so:

- `BookRoom` and `RescheduleBooking` require `companyId` in the command. The caller must read the member first,
  outside any consistency boundary, only to learn which boundary to load (`EventSourcedDriver::companyOf()`), and
  `decide()` must re-verify it (`member.company_mismatch`). The rule "the member's company pays" is enforced by a
  check instead of being structural.
- In the state-store flavor, `CancelBooking`, `RescheduleBooking` and `CloseRoomForDay` update rows identified by the
  loaded booking. They inject `StateStore` into `decide()` and load those rows by hand.

The classic flavor writes `$member->company`.

**Direction**: let a state parameter bind to a path into another state parameter, for example
`#[BindTo('member.companyId')]` combined with a command value. The aspect resolves parameters in dependency order:
one load round per level, one append condition (or row expectation) per loaded boundary. The concurrency model
stays the same, since each loaded boundary is still guarded.

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

## 3. Event tags are single-valued per dimension (Design)

`Event::$domainIds` is `array<string, string>`: an event is tagged with at most one value per dimension, and a
decision state is loaded from exactly one tag. A reschedule leaves one room-day and enters another, so a single
domain event cannot be seen by both schedules. It is split into `BookingRescheduledFrom` and
`BookingRescheduledTo`, and every state handles both halves. A transfer between two wallets, or a meeting across
two rooms, would hit the same limit.

The classic model updates one row.

**Direction**: allow a dimension to carry a set of values (DCB-style tags are a set of strings), so one
`BookingRescheduled` event can be tagged `roomDay: [old, new]`. This touches the event store schema (`hstore`),
the query matching and the append-condition locks, so it is a design change rather than an attribute.

## 4. Derived identities have no first-class place (Feature; Mapper bug fixed)

Boundaries such as `roomDay` and `companyMonth` are computed from command data.

- `#[BindTo]` only accepts a property path, so commands expose derived IDs as PHP property hooks. Hooks cannot be
  `readonly`, so these commands cannot be `readonly class` like the others. The same goes for events that derive
  their tags with hooks (`RoomBooked` and the other booking events).
- **Fixed:** the generated Mapper used to treat a get-only virtual property as data and assign it after
  construction ("Property RoomBooked::$roomDayId is read-only"). The Mapper compiler now ignores virtual
  properties entirely: they are neither normalized nor denormalized, and they don't count toward an `#[AsValue]`
  object's single property.

**Direction**: `#[BindTo]` (and possibly `#[DomainIdentifier]`) could accept a method, so identities can be computed
without hooks and classes can stay `readonly`.

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

## 7. State store: set-based rules require hand-maintained denormalized states (Design, for this flavor)

`StateStore` loads by id only. "No overlap in this room on this day" and "sum of minutes this month" are
queries in the classic flavor. Here they need extra states that duplicate booking data:

- `RoomDayScheduleState` + `ScheduledSlot` and `CompanyMonthUsageState` + `Charge`, backed by parent tables whose only
  column is the id, since a collection needs an owner row.
- One booking writes three states, and cancel, reschedule and close must keep those copies in sync by hand.
  Nothing detects drift between `cw_ss_bookings` and the schedules.

**Direction**: a query-scoped state, such as the slots of `cw_ss_bookings` where `room_id = ?` and the day matches,
guarded by a row-set expectation over that scope. `MappedRowSet` already has scoped row sets and
`expectSameRowKeys()`, but the scope is limited to key columns.

## 8. State store: manual loads are unsafe by default (Feature)

Because of finding 1, some state-store decisions load rows themselves. Two problems follow:

- A manual `load()` without `expect()` silently becomes a blind overwrite (lost update). Nothing warns.
- Loading a row that is also a decision parameter (same-day reschedule) creates two instances. Saving both lets the
  last one win. `RescheduleBooking` must branch on "same room-day?" to reuse the parameter instance.

Smaller papercuts in the same flavor:

- A non-nullable state parameter throws `StateNotFoundException` instead of a domain violation, so every parameter
  ends up nullable with manual null checks.
- `?BookingState $booking` is declared in `BookRoom` only to get `expectMissing()`.
- Every column needs an explicit `#[StateColumn('snake_name')]`.
- DDL for state tables is written by hand (`CoworkingSchema`).

**Direction**: inside a decision, loads should go through an identity map that registers expectations
automatically. This also covers finding 1's manual loads, until dependent binding exists.

## 9. Smaller observations

- Two `ConcurrencyException` classes exist (`Ultra\EventStore\Exception` and `Ultra\UnitOfWork\Exception`). A
  retry-on-conflict policy has to catch both. Nothing retries a decision on conflict today, and a retry advice
  would fit the design naturally.
- The Mapper does not support `Ultra\Time\DatePoint`, the type `ClockInterface::now()` returns (exact class match in
  `DateTimeCodeGeneratorBuilder`). States and events use `\DateTimeImmutable` instead.

## Where the design wins

The comparison is not one-sided:

- **Concurrency is correct by construction and testable.** Both Ultra flavors reject a concurrent overlapping
  booking and a concurrent quota drain, and accept unrelated concurrent bookings. `ConcurrentDecisionsTest` proves
  it deterministically, with a second kernel committing between load and flush. The classic flavor is only
  correct because every use case locks the company row and then the room rows in a fixed order. Forgetting a lock,
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

## Verdict

The DecisionHandler design is sound for invariants whose boundaries are known from the command. The event-sourced
flavor, including composition through the unit of work, is competitive with the classic model and safer
under concurrency.

The design is weakest where the classic model is strongest: boundaries that depend on loaded data (1), and
set-based rules in the state-store flavor (7). Finding 1 looks solvable inside the design with dependent binding.
Finding 7 questions what the state-store flavor is for: as soon as a rule spans rows, it pushes the developer into
maintaining materialized views by hand. Finding 3 is the only limit rooted in the event model itself.

Suggested order of work: dependent binding (1), then deciding the
state-store flavor's scope (7, 8), then multi-valued tags (3).
