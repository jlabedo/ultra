# Coworking example: DecisionHandler stress test

The same booking domain is implemented four times against one Postgres database, and a single scenario suite runs against every implementation:

| Flavor | Code | Persistence |
|---|---|---|
| Event sourced | `src/Booking/EventSourced` | `#[DecisionHandler]` + `EventSourcedDecisionAspect`, Postgres event store |
| State store | `src/Booking/StateStore` | `#[DecisionHandler]` + `StateStoreDecisionAspect`, mapped Postgres tables |
| State store direct | `src/Booking/StateStoreDirect` | Plain service, `StateStore`, deferred advisory guards and SQL expectations |
| Classic | `src/Booking/Classic` | Doctrine ORM entities, repository queries, pessimistic locks |

`src/Booking/Domain` holds the pure rules (`BookingPolicy`, `Slot`) shared by all four, so the flavors only differ in how state is loaded, checked and saved.

The direct variant uses four normalized tables (company, member, room, booking), like Doctrine. It has no schedule
or usage copies to maintain. `BookingService` calls `UnitOfWorkRunner::run()` explicitly, without the mediator,
`SelfHandler` or `DecisionHandler`. The runner prepares the decision outside a transaction and flushes once in one
transaction: acquire the requested manual guards, replay the SQL expectations, then check and write the row plans.
The developer selects company and room guards for every action affecting quota or availability. No `SERIALIZABLE`
isolation or pessimistic row locks are used during the decision.

The service reads through `StateStore::observe()`, which registers optimistic state or absence expectations
automatically. Its helpers only translate missing or existing states into domain violations. Bulk closure also
observes the bookings hydrated by the repository.

Manual queries observe committed rows before the write plan. `BookingRepository` registers their results with
`observeQuery()` and separately overlays pending booking changes for subsequent decisions, including weekly series.
It participates in unit-of-work cleanup; StateStore owns its writes. No intermediate flush is needed. Duplicate
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
