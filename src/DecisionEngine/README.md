# Decision Engine

Event-sourcing decision pipeline built on the mediator. Commands declare their consistency boundary via their `decide()` method signature; a single decision aspect loads the right streams, hydrates **states** (reducers), resolves services, and appends emitted **events** with optimistic concurrency.

## Design

- **Decider pattern**: Each command owns the decision logic in a method marked with `#[DecisionHandler]`. The decision aspect discovers what to load and inject by reflecting on that method.
- **States are reducers**: State classes use `ReducerTrait` and private handlers (one typed event parameter) to fold events from a single stream. No aggregate root is required.
- **Consistency by signature**: Parameters of the decision handler are either **state** (hydrated from the event store by stream) or **services** (resolved from the container). The aspect infers which is which and loads only the streams needed for that command.
- **Identity by convention**: A state’s stream is identified by a property annotated with `#[DomainIdentifier('name')]`; the engine reads the value from the command’s `{name}Id` property (or a custom property via `#[BindTo]`).
- **Events are DTOs**: Events are plain classes with `#[Event('type.name')]` and `#[DomainIdentifier]` on properties. Stream routing is derived from those attributes when appending.

## Attributes

| Attribute | Target | Purpose |
|-----------|--------|---------|
| `#[DecisionHandler]` | Method | Marks a method as a decision handler for the aspect. |
| `#[Handles]` | Method | Mediator handler marker (required for discovery). |
| `#[DomainIdentifier('name')]` | Property | Marks the stream dimension; aspect uses command property `{name}Id` (or `#[BindTo]`) for the stream value. |
| `#[Event('type.name')]` | Class | Event type name for storage and routing. |
| `#[BindTo('propertyName')]` | Parameter | Override convention: use this command property for the state’s stream id. |

## Core types

- **Violation** – `code` + `message` for invariant failures.
- **InvariantViolation** – Exception carrying `list<Violation>` (thrown by commands when `check()` fails).
- **ReducerTrait** – `fromEvents(array): static`, `handledEventTypes(): list<string>`. Private methods with a single typed event parameter are used as handlers.

## Usage

### Running a command

```php
$mediator = /* mediator instance */;

$events = $mediator->send($command);  // returns emitted event DTOs
```

Decision commands are self-handling messages:

```php
use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\Mediator\Attribute\Handles;

final class CreateCourseCommand
{
    #[Handles]
    #[DecisionHandler]
    public function decide(?CourseCapacityState $course): array
    {
        // return events
    }
}
```

`send()` will:

1. Resolve stream queries from the command instance.
2. Load events for each stream and hydrate states (`State::fromEvents(...)`).
3. Pass `null` for nullable state parameters when a stream has no events; throw if a required stream is missing.
4. Resolve service parameters from the container.
5. Call the decision handler and route returned events to `AppendableEvent`.
6. Append with an expected version (optimistic concurrency).

### Read-only validation

```php
$violations = $mediator->send($command, ['decision_mode' => 'check']);
```

Uses the same loading and state hydration, then calls `$command->check(...)` (required) and returns the violation list. No append.

### Nullable state

Use a nullable state parameter when the stream may not exist yet (e.g. first time a student subscribes):

```php
public function decide(
    CourseCapacityState $course,
    ?StudentSubscriptionState $student,
): array { ... }
```

Empty stream for `$student` yields `null`; your logic can treat that as “no prior subscriptions”.

## Example

A full course/student subscription example (events, states, commands, and tests) lives under **`Tests/Example/`**: create course, subscribe/unsubscribe students, with `check()` and nullable student state.

## Dependencies

- **Event store**: `Ultra\EventStore\EventStore` (load by `StreamQuery`, append with optional `StreamVersion`). Build queries with the fluent API: `StreamQuery::forStream(DomainIdentifier ...)->ofTypes(...)` for one branch; `StreamQuery::anyOf(StreamQuery ...$branches)` for OR of branches.
- **PSR-11 container** for service parameters.
- **EventClassRegistry**: maps event type strings (from `#[Event]`) to DTO class names so the engine can rehydrate events when building state from stored events.
- **UltraNormalizer**: normalizes event DTOs into payload arrays and rehydrates events from stored payloads (codegen-backed).
