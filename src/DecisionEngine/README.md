# Decision Engine

Event-sourcing command engine that runs **commands** against state hydrated from an event store. Commands declare their consistency boundary via their `decide()` method signature; the engine loads the right streams, hydrates **states** (reducers), resolves services from a container, and appends emitted **events** with optimistic concurrency.

## Design

- **Decider pattern**: Each command owns the decision logic in a `decide()` method. The engine discovers what to load and inject by reflecting on that method.
- **States are reducers**: State classes use `ReducerTrait` and private handlers (one typed event parameter) to fold events from a single stream. No aggregate root is required.
- **Consistency by signature**: Parameters of `decide()` are either **state** (hydrated from the event store by stream) or **services** (resolved from the PSR-11 container). The engine infers which is which and loads only the streams needed for that command.
- **Identity by convention**: A state’s stream is identified by a property annotated with `#[DomainIdentifier('name')]`; the engine reads the value from the command’s `{name}Id` property (or a custom property via `#[BindTo]`).
- **Events are DTOs**: Events are plain classes with `#[Event('type.name')]` and `#[DomainIdentifier]` on properties. Stream routing is derived from those attributes when appending.

## Attributes

| Attribute | Target | Purpose |
|-----------|--------|---------|
| `#[DomainIdentifier('name')]` | Property | Marks the stream dimension; engine uses command property `{name}Id` (or `#[BindTo]`) for the stream value. |
| `#[Event('type.name')]` | Class | Event type name for storage and routing. |
| `#[BindTo('propertyName')]` | Parameter | Override convention: use this command property for the state’s stream id. |

## Core types

- **Violation** – `code` + `message` for invariant failures.
- **InvariantViolation** – Exception carrying `list<Violation>` (thrown by commands when `check()` fails).
- **ReducerTrait** – `fromEvents(array): static`, `handledEventTypes(): list<string>`. Private methods with a single typed event parameter are used as handlers.

## Usage

### Running a command

```php
$engine = new Engine(
    $eventStore,
    new CommandMetadataResolver(),
    new StreamIdentityResolver(),
    new EventRouter(),
    $eventClassRegistry,  // maps event type names to DTO classes
    $container
);

$events = $engine->handle($command);  // returns emitted event DTOs
```

`handle()` will:

1. Resolve command metadata from `decide()` (and optional `check()`).
2. Resolve stream queries from the command instance.
3. Load events for each stream and hydrate states (`State::fromEvents(...)`).
4. Pass `null` for nullable state parameters when a stream has no events; throw if a required stream is missing.
5. Resolve service parameters from the container.
6. Call `$command->decide(...)` and route returned events to `AppendableEvent`.
7. Append with an expected version (optimistic concurrency); merge version map for multi-stream commands.

### Read-only validation

```php
$violations = $engine->check($command);
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
