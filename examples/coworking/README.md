# Coworking example: DecisionHandler stress test

The same booking domain is implemented four times against one Postgres database, and a single scenario suite runs against every implementation:

| Flavor | Code | Persistence |
|---|---|---|
| Event sourced | `src/Booking/EventSourced` | `#[DecisionHandler]` + `EventSourcedDecisionAspect`, Postgres event store |
| State store | `src/Booking/StateStore` | `#[DecisionHandler]` + `StateStoreDecisionAspect`, mapped Postgres tables |
| State store direct | `src/Booking/StateStoreDirect` | Plain service, `StateStore`, compiled query guards and SQL expectations |
| Classic | `src/Booking/Classic` | Doctrine ORM entities, repository queries, pessimistic locks |

`src/Booking/Domain` holds the pure rules (`BookingPolicy`, `Slot`) shared by all four, so the flavors only differ in how state is loaded, checked and saved.

The direct variant uses four normalized tables (company, member, room, booking), like Doctrine. It has no schedule
or usage copies to maintain. `BookingService` calls `UnitOfWorkRunner::run()` explicitly, without the mediator,
`SelfHandler` or `DecisionHandler`. The runner prepares the decision outside a transaction and flushes once in one
transaction: acquire the query guards, replay the SQL expectations, then check and write the row plans.
`StateTableRegistry` owns guard requests; the dedicated `PostgresGuardLockAdapter` uses the same connection as
the mapped adapters and repository queries. `MappedRowSet` handles only row changes and optimistic expectations.
The repository's constructor declares company-usage and room-booking dependencies with `#[DefineQueryGuard]`.
Queries register shared guards through `QueryGuard::observe()`; affected writes automatically register exclusive
guards, including cancellations and both rooms of a move. The service requests no manual locks. Company plan
changes rely on optimistic row expectations. No `SERIALIZABLE` isolation or pessimistic row locks are used during
the decision. See the [query guard contract](../../src/StateStore/QUERY_GUARDS.md) for its supported scope and responsibilities.

The service reads through `StateStore::observe()`, which registers optimistic state or absence expectations
automatically. Like Doctrine, the service uses typed `company()` and `booking()` helpers for required loads and
checks duplicate identifiers directly in each creation operation. Bulk closure also observes the bookings hydrated
by the repository.

Manual queries observe committed rows before the write plan. `BookingRepository` registers their results with
`QueryGuard::observe()`, which rejects a read if local writes affect the same declared guard and scope. Weekly
series validate every occurrence, read existing usage once per month and check the total requested minutes before
saving any booking. The repository keeps no pending-write overlay; StateStore owns all writes and cleanup.
No intermediate flush is needed. Duplicate
emails also have a database unique constraint, as in the Doctrine variant. Foreign keys are deferred to commit so
new related states can be saved in one flush regardless of table order.

## Domain

Companies buy a monthly pool of meeting-room minutes shared by their members.

- A member's email is unique, ignoring case.
- A room cannot have two bookings overlapping in time; slots follow a 30-minute grid, opening hours and a maximum length.
- The company pool for the month of the booking cannot be exceeded.
- Cancelling at least 24 hours before the start refunds the minutes; later cancellations free the room but stay charged.
- A booking can be rescheduled to another room, day or month until 24 hours before it starts.
- A weekly series is booked all-or-nothing.
- Operators can close a room for a day: every booking that day is cancelled and refunded.

These rules were picked because their consistency boundaries are not a fixed set of entities known from the command:
the quota boundary depends on the member's company, a series touches a runtime number of room-days, and a reschedule touches two room-days and possibly two company-months.

## Running

```bash
docker compose up -d   # from the repository root
composer install
vendor/bin/phpunit
```

See [FINDINGS.md](FINDINGS.md) for the design review.
