# Decision bindings and examples

Stateful decision handling built on the mediator, with two persistence approaches: event-sourced states and stored states. Decision aspects load state parameters and apply recognized returned effects with optimistic concurrency protection. The signature declares injected state dependencies; callers remain responsible for additional query and service dependencies.

See the [shared concepts and consistency responsibilities](stateful.md). The event-sourced path is described below; the stored-state path uses [StateStore](../stored-state/README.md).

## Design

- **Decision handlers**: Decision logic can live on a self-handling command or a separate handler. A method marked with `#[DecisionHandler]` declares the state dependencies that the compiler analyzes for loading and injection.
- **States are event-sourced entities**: State classes come from `ultra/event-sourced-state` and use `#[EventSourcedEntity]` plus public `#[EventSourcingHandler]` methods (one typed event parameter) to fold the events selected by their loader. No aggregate root is required.
- **Consistency by signature**: Parameters of the decision handler are either **state** (hydrated from the event store by stream) or **services** (resolved from the container). The aspect infers which is which and loads only the streams needed for that command.
- **Identity by convention**: For every tag declared by `#[EventSourcedEntity(tags: ['course', 'student'])]`, the engine accepts the command’s `{tag}Id` property, its `{tag}` property, or its public instance `{tag}Id()` method. Exactly one candidate must exist; multiple candidates are a compile-time error; `#[BindTo]` chooses explicitly.
- **Events are DTOs**: Events are plain classes with `#[Event('type.name')]` and `#[DomainIdentifier]` on properties. Stream routing is derived from those attributes when appending.

Event-sourced state bindings accept `string|\Stringable` identifiers from properties or methods,
including dependent methods. Values are converted before loading under the
[domain identifier contract](../event-sourcing/event-store.md#domain-identifier-values).
Other value types fail with `LogicException` identifying the state parameter and actual type.

## Attributes

| Attribute | Target | Purpose |
|-----------|--------|---------|
| `#[DecisionHandler]` | Method | Marks a method as a decision handler for the aspect. |
| `#[SelfHandler]` | Method | Mediator self-handler marker for commands that handle themselves. |
| `#[EventSourcedEntity(tags: ['course', 'student'])]` | Class | Marks a decision state and declares its identity tags. Decision handlers require at least one tag and bind one value per tag. Aliases reuse their target tag’s value; see [state matching and aliases](../event-sourcing/states.md). |
| `#[EventSourcingHandler]` | Method | Marks a public event replay handler on a state / entity. |
| `#[Event('type.name')]` | Class | Event type name for storage and routing. |
| `#[DomainIdentifier('name')]` | Property | Marks an event DTO identifier used for routing and query matching. |
| `#[BindTo('property.path')]` | Parameter | Override convention: use this message property path for the state’s id. |
| `#[BindTo(method: 'name')]` | Parameter | Override convention: call this public message method for the state’s id. Its parameters are other states of the same handler, matched by name (see [Dependent states](#dependent-states)). |
| `#[BindTo('property.path', tag: 'course')]` / `#[BindTo(method: 'name', tag: 'student')]` | Parameter | Override one event-sourced identity tag. Repeat for different tags; tags without overrides keep their convention. |

## Core types

- **Violation** – `code` + `message` for invariant failures.
- **InvariantViolation** – Exception carrying `list<Violation>`, thrown by decision handlers when a rule is broken.
- **DecisionMode** – Context key and value that switch a send to experimental CHECK mode (see [its current limitations](stateful.md#experimental-check-mode)).
- **EventSourcedEntityLoader** – Pre-compiled loader that builds the typed query for an entity and rehydrates it from a shared batch of loaded `Ultra\EventStore\Event` values.

## Usage

### Running a command

```php
$mediator = /* mediator instance */;

$events = $mediator->send($command);  // returns emitted event DTOs
```

Decision commands can be self-handling messages, as in this example, or use a separate `#[Handles]` handler:

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
2. Load each dependency round in a single query and hydrate each state by replaying its `#[EventSourcingHandler]` methods over the shared batch.
3. Always pass a state object, even when the stream has no events yet.
4. Resolve service parameters from the container.
5. Call the decision handler and route returned events to `Event`.
6. Append with an append condition (optimistic concurrency).

### Multi-tag states

Each event-sourced tag has its own binding. For a state declaring `tags: ['course', 'student']`,
each tag resolves independently by convention: properties `courseId` or `course` for the first tag,
properties `studentId` or `student` for the second, or public instance methods `courseId()` and
`studentId()`. Bare tag names apply only to properties; a `course()` method is not a conventional source.

Exactly one candidate must exist per tag. Declaring both `course` and `courseId`, or either property
alongside `courseId()`, fails during compilation, even if they would return equal values. The error
lists the competing sources. `#[BindTo]` selects explicitly and bypasses conventional ambiguity for
that tag. Stored states continue to use their `#[StateId]` property name as their convention.

Use a tag-specific override when the message exposes a different property path or method:

```php
#[SelfHandler]
#[DecisionHandler]
public function decide(
    #[BindTo('reference.courseId', tag: 'course')]
    #[BindTo(method: 'selectedStudent', tag: 'student')]
    CourseStudentActivityState $activity,
): array {
    // return events
}
```

`BindTo` requires `tag` for a multi-tag state; single-tag event-sourced states and stored states
continue to accept an override without it. Unknown tags, alias names used as identity tags, and
duplicate overrides for the same tag fail during compilation. Each resolved event-sourced value
must be `string|\Stringable`; invalid values identify the state parameter and tag in the error.

The state’s [matching groups](../event-sourcing/states.md#matching-event-tags) determine whether events
match individual tags or a combination. Binding multiple tags does not change those groups. The aspect
passes all identifiers to both query construction and hydration; the resulting query, including alias
branches, participates in optimistic concurrency protection.

### Dependent states

A state’s id, or any individual event-sourced tag value, can depend on another loaded state: compute it with a public method of the message whose parameters are
other state parameters of the same handler, **matched by name** and typed with the same state class. The method is
found by convention (a method with the conventional id name, such as `companyMonthId()` for the `companyMonth` tag, provided neither conventional property exists) or
named with `#[BindTo(method: 'name')]` (add `tag` for a multi-tag state). A state loads only after
all dependencies from all of its tag bindings are available; a cycle through any tag fails compilation.

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
  loaded or expected. A state depending on a nullable state must therefore be nullable itself. Every loaded state is
  expected, and the expectation is checked at flush even if the state is not saved, so a concurrent change to a
  source row (the member moved company) makes the flush fail.
- Stored-state `#[StateReader]` handlers use the same binding and loading rules; see [their consistency responsibilities](stateful.md#consistency-responsibilities).
- A method bound by convention adds a loading round without any attribute on the parameter: read the message's id
  methods to see a handler's dependencies.
- Limitation: a method can only depend on states of the same flavor. An event-sourced state cannot feed a state-store
  id, or the reverse, because each aspect loads its own states independently.

### Experimental CHECK mode

Read the [CHECK contract and limitations](stateful.md#experimental-check-mode) before using or changing this proof of concept.

```php
use Ultra\StatefulMessageHandling\DecisionMode;

$violations = $mediator->send($command, [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK]);
```

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

The [course commands](../../src/MessageHandling/Tests/Stateful/Example/Command) use the event-sourced [events](../../src/EventSourcing/Tests/State/Example/Event) and [states](../../src/EventSourcing/Tests/State/Example/State). The [guarantee suites](stateful.md#test-ownership-and-maintenance) exercise course creation, subscriptions, unsubscriptions and CHECK alongside stored-state decisions.

## Dependencies

- **Event store**: `Ultra\EventStore\EventStore` (load by `StreamQuery`, append with zero-to-many `AppendCondition` values). Build queries with the fluent API: `StreamQuery::forStream(DomainIdentifier ...)->ofTypes(...)` for one branch; `StreamQuery::anyOf(StreamQuery ...$branches)` for OR of branches.
- **PSR-11 container** for service parameters.
- **EventRegistryInterface**: maps event type strings (from `#[Event]`) to DTO class names so the engine can rehydrate events when building state from loaded events.
- **UltraNormalizer**: normalizes event DTOs into payload arrays and rehydrates events from loaded payloads (codegen-backed).

## Symfony

When using Symfony, register `Ultra\EventSourcedState\EventSourcedStateExtension` for entity loader compilation and `Ultra\StatefulMessageHandling\StatefulMessageHandlingExtension` for the decision aspect.

## Notes

- Decision-state hydration and the projection runtime both consume `Ultra\EventStore\Event`.
- If you wrap the store with `Ultra\EventStore\EventStoreUnitOfWork`, states can observe pending in-process events through `load()`, while optimistic concurrency still stays tied to the wrapped store’s persisted `AppendCondition`.
