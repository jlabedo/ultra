# Stateful Message Handling

Event-sourcing decision pipeline built on the mediator. Commands declare their consistency boundary via their `decide()` method signature; a single decision aspect loads the right streams, hydrates **states**, resolves services, and appends emitted **events** with optimistic concurrency.

## Design

- **Decider pattern**: Each command owns the decision logic in a method marked with `#[DecisionHandler]`. The decision aspect discovers what to load and inject by reflecting on that method.
- **States are event-sourced entities**: State classes come from `ultra/event-sourced-state` and use `#[EventSourcedEntity]` plus public `#[EventSourcingHandler]` methods (one typed event parameter) to fold events from a single stream. No aggregate root is required.
- **Consistency by signature**: Parameters of the decision handler are either **state** (hydrated from the event store by stream) or **services** (resolved from the container). The aspect infers which is which and loads only the streams needed for that command.
- **Identity by convention**: A state’s stream dimension comes from its single `#[EventSourcedEntity(tags: ['name'])]` tag; the engine reads the value from the command’s `{name}Id` property or, if there is none, its public `{name}Id()` method. A name matching both is a compile-time error; `#[BindTo]` chooses explicitly.
- **Events are DTOs**: Events are plain classes with `#[Event('type.name')]` and `#[DomainIdentifier]` on properties. Stream routing is derived from those attributes when appending.

## Attributes

| Attribute | Target | Purpose |
|-----------|--------|---------|
| `#[DecisionHandler]` | Method | Marks a method as a decision handler for the aspect. |
| `#[SelfHandler]` | Method | Mediator self-handler marker for commands that handle themselves. |
| `#[EventSourcedEntity(tags: ['name'])]` | Class | Marks a decision state / entity and declares the stream tags it can be loaded from. Decision handlers currently require exactly one tag; `aliases: ['otherRole' => 'name']` also loads events whose `otherRole` identifier equals the state's id (see the event-sourced state README). |
| `#[EventSourcingHandler]` | Method | Marks a public event replay handler on a state / entity. |
| `#[Event('type.name')]` | Class | Event type name for storage and routing. |
| `#[DomainIdentifier('name')]` | Property | Marks an event DTO identifier used for routing and query matching. |
| `#[BindTo('property.path')]` | Parameter | Override convention: use this message property path for the state’s id. |
| `#[BindTo(method: 'name')]` | Parameter | Override convention: call this public message method for the state’s id. Its parameters are other states of the same handler, matched by name (see [Dependent states](#dependent-states)). |

## Core types

- **Violation** – `code` + `message` for invariant failures.
- **InvariantViolation** – Exception carrying `list<Violation>`, thrown by decision handlers when a rule is broken.
- **DecisionMode** – Context key and value that switch a send to read-only check mode.
- **EventSourcedEntityLoader** – Pre-compiled loader that builds the typed query for an entity and rehydrates it from a shared batch of loaded `Ultra\EventStore\Event` values.

## Usage

### Running a command

```php
$mediator = /* mediator instance */;

$events = $mediator->send($command);  // returns emitted event DTOs
```

Decision commands are self-handling messages:

```php
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\MessageHandling\Attribute\SelfHandler;

final class CreateCourseCommand
{
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseCapacityState $course): array
    {
        // return events
    }
}
```

`send()` will:

1. Resolve stream queries from the command instance.
2. Load all needed events in a single query and hydrate each state by replaying its `#[EventSourcingHandler]` methods over the shared batch.
3. Always pass a state object, even when the stream has no events yet.
4. Resolve service parameters from the container.
5. Call the decision handler and route returned events to `Event`.
6. Append with an append condition (optimistic concurrency).

### Dependent states

A state’s id can depend on another loaded state: compute it with a public method of the message whose parameters are
other state parameters of the same handler, **matched by name** and typed with the same state class. The method is
found by convention (a method with the conventional id name, such as `companyMonthId()` for the `companyMonth` tag) or
named with `#[BindTo(method: 'name')]`.

```php
final readonly class BookRoom
{
    public function __construct(public string $memberId, public Slot $slot) {}

    public function companyMonthId(MemberState $member): string
    {
        return $member->companyId.'@'.$this->slot->month();
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        MemberState $member,
        CompanyMonthUsageState $usage, // companyMonth tag: bound to companyMonthId() by convention
    ): RoomBooked { /* ... */ }
}
```

- A method without parameters is a derived id (e.g. a normalized email), computed from the message alone.
- Dependencies are resolved when the container is compiled into loading rounds: first the states bound to
  properties or parameterless methods, then the states whose sources are all loaded. An unknown parameter name, a
  parameter that is not a state of the same flavor, a type mismatch, or a cycle fails the compilation. For a
  separate `#[Handles]` handler, the method belongs to the message class, not to the handler.
- Event-sourced states: each round is one `load()`, and every round’s append condition guards the final append, so
  a concurrent change to a source stream (the member moved company) makes the append fail.
- State-store states: when a source state is missing (`null`), the dependent state is `null` too, without being
  loaded or expected. A state depending on a nullable state must therefore be nullable itself.
- `#[StateReader]` handlers follow the same rules.
- A method bound by convention adds a loading round without any attribute on the parameter: read the message's id
  methods to see a handler's dependencies.
- Limitation: a method can only depend on states of the same flavor. An event-sourced state cannot feed a state-store
  id, or the reverse, because each aspect loads its own states independently.

### Read-only validation

```php
use Ultra\StatefulMessageHandling\DecisionMode;

$violations = $mediator->send($command, [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK]);
```

Runs the decision handler with the same states and services, then discards its effects: nothing is appended (or saved, for state-store decisions) and no event is published. The send returns the violations of a thrown `InvariantViolation`, or an empty list when the decision would succeed. Other exceptions propagate.

Check mode reports what `decide()` reports: group all rules you want surfaced together into one `InvariantViolation`.

### Stream Existence

If your domain needs to distinguish “no events yet” from “existing stream”, model that explicitly on the state:

```php
final class CourseCapacityState
{
    public function exists(): bool { ... }
}
```

The aspect always hydrates an object. Commands should ask the state whether it has been initialized instead of relying on `null`.

## Example

A full course/student subscription example (events, states, commands, and tests) lives under **`Tests/Example/`**: create course, subscribe/unsubscribe students, with check mode and explicit course existence on the state.

## Dependencies

- **Event store**: `Ultra\EventStore\EventStore` (load by `StreamQuery`, append with zero-to-many `AppendCondition` values). Build queries with the fluent API: `StreamQuery::forStream(DomainIdentifier ...)->ofTypes(...)` for one branch; `StreamQuery::anyOf(StreamQuery ...$branches)` for OR of branches.
- **PSR-11 container** for service parameters.
- **EventRegistryInterface**: maps event type strings (from `#[Event]`) to DTO class names so the engine can rehydrate events when building state from loaded events.
- **UltraNormalizer**: normalizes event DTOs into payload arrays and rehydrates events from loaded payloads (codegen-backed).

## Symfony

When using Symfony, register `Ultra\EventSourcedState\EventSourcedStateExtension` for entity loader compilation and `Ultra\StatefulMessageHandling\StatefulMessageHandlingExtension` for the decision aspect.

## Notes

- Decision-state hydration works on `Ultra\EventStore\Event`, not projection-side persisted events with `eventId`.
- If you wrap the store with `Ultra\EventStore\EventStoreUnitOfWork`, states can observe pending in-process events through `load()`, while optimistic concurrency still stays tied to the wrapped store’s persisted `AppendCondition`.
