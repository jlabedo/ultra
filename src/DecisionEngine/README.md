# Decision Engine

Event-sourcing decision pipeline built on the mediator. Commands declare their consistency boundary via their `decide()` method signature; a single decision aspect loads the right streams, hydrates **states**, resolves services, and appends emitted **events** with optimistic concurrency.

## Design

- **Decider pattern**: Each command owns the decision logic in a method marked with `#[DecisionHandler]`. The decision aspect discovers what to load and inject by reflecting on that method.
- **States are event-sourced entities**: State classes use `#[EventSourcedEntity]` plus public `#[EventSourcingHandler]` methods (one typed event parameter) to fold events from a single stream. No aggregate root is required.
- **Consistency by signature**: Parameters of the decision handler are either **state** (hydrated from the event store by stream) or **services** (resolved from the container). The aspect infers which is which and loads only the streams needed for that command.
- **Identity by convention**: A state’s stream dimension comes from its single `#[EventSourcedEntity(tags: ['name'])]` tag; the engine reads the value from the command’s `{name}Id` property (or a custom property via `#[BindTo]`).
- **Events are DTOs**: Events are plain classes with `#[Event('type.name')]` and `#[DomainIdentifier]` on properties. Stream routing is derived from those attributes when appending.

## Attributes

| Attribute | Target | Purpose |
|-----------|--------|---------|
| `#[DecisionHandler]` | Method | Marks a method as a decision handler for the aspect. |
| `#[SelfHandler]` | Method | Mediator self-handler marker for commands that handle themselves. |
| `#[EventSourcedEntity(tags: ['name'])]` | Class | Marks a decision state / entity and declares the stream tags it can be loaded from. Decision handlers currently require exactly one tag. |
| `#[EventSourcingHandler]` | Method | Marks a public event replay handler on a state / entity. |
| `#[Event('type.name')]` | Class | Event type name for storage and routing. |
| `#[DomainIdentifier('name')]` | Property | Marks an event DTO identifier used for routing and query matching. |
| `#[BindTo('propertyName')]` | Parameter | Override convention: use this command property for the state’s stream id. |

## Core types

- **Violation** – `code` + `message` for invariant failures.
- **InvariantViolation** – Exception carrying `list<Violation>` (thrown by commands when `check()` fails).
- **EventSourcedEntityLoader** – Pre-compiled loader that builds the typed query for an entity and rehydrates it from a shared batch of loaded `Ultra\EventStore\Event` values.

## Usage

### Running a command

```php
$mediator = /* mediator instance */;

$events = $mediator->send($command);  // returns emitted event DTOs
```

Decision commands are self-handling messages:

```php
use Ultra\DecisionEngine\Attribute\DecisionHandler;
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
6. Append with an expected query version (optimistic concurrency).

### Read-only validation

```php
$violations = $mediator->send($command, ['decision_mode' => 'check']);
```

Uses the same loading and state hydration, then calls `$command->check(...)` (required) and returns the violation list. No append.

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

A full course/student subscription example (events, states, commands, and tests) lives under **`Tests/Example/`**: create course, subscribe/unsubscribe students, with `check()` and explicit course existence on the state.

## Dependencies

- **Event store**: `Ultra\EventStore\EventStore` (load by `StreamQuery`, append with zero-to-many `QueryVersion` tokens). Build queries with the fluent API: `StreamQuery::forStream(DomainIdentifier ...)->ofTypes(...)` for one branch; `StreamQuery::anyOf(StreamQuery ...$branches)` for OR of branches.
- **PSR-11 container** for service parameters.
- **EventRegistryInterface**: maps event type strings (from `#[Event]`) to DTO class names so the engine can rehydrate events when building state from loaded events.
- **UltraNormalizer**: normalizes event DTOs into payload arrays and rehydrates events from loaded payloads (codegen-backed).

## Notes

- Decision-state hydration works on `Ultra\EventStore\Event`, not projection-side persisted events with `eventId`.
- If you wrap the store with `Ultra\EventStore\EventStoreUnitOfWork`, states can observe pending in-process events through `load()`, while optimistic concurrency still stays tied to the wrapped store’s persisted `QueryVersion`.
