# Event Sourced State

Event-sourced state loading on top of `Ultra\EventStore`.

## Purpose

This package owns the event-sourced state model:

- `#[EventSourcedEntity]` state classes declare the stream tags they can be loaded from.
- `#[EventSourcingHandler]` methods fold event DTOs into state objects.
- `#[MatchTag]` disambiguates handlers when an event can match several entity tags.
- `EventSourcedEntityLoader` is the compiled runtime contract used to query and hydrate state.
- `EventSourcedStateStore` loads typed state from an event store through compiled loaders.

The companion `ultra/event-sourced-state-compiler` package analyzes event-sourced entities and generates optimized loaders.

## Matching Event Tags

An event-sourced entity is loaded from one or more stream tag dimensions. For example:

```php
#[EventSourcedEntity(tags: ['course', 'student'])]
final class CourseStudentActivityState
{
}
```

Each `#[EventSourcingHandler]` method handles one event type. The compiler reads the event's
`#[DomainIdentifier]` attributes and intersects those tag names with the entity tags:

- if the event matches none of the entity tags, compilation fails;
- if the event matches exactly one entity tag, that tag is used automatically;
- if the event matches several entity tags, the handler must use `#[MatchTag]` to say which query group or groups should include the event.

`#[MatchTag]` is only needed for the ambiguous case. It does not change how the handler is
called once an event is loaded; it changes which event-store criteria the generated loader uses
when it searches for events.

Use one `#[MatchTag]` attribute per independent query group:

```php
#[EventSourcingHandler]
#[MatchTag('course')]
#[MatchTag('student')]
public function onStudentSubscribed(StudentSubscribed $event): void
{
    // Loaded when querying the course stream, and also when querying the student stream.
}
```

Use multiple tag names in the same `#[MatchTag]` when the event should only be loaded for the
combined tag group:

```php
#[EventSourcingHandler]
#[MatchTag('course', 'student')]
public function onPairConfirmed(SubscriptionPairConfirmed $event): void
{
    // Loaded only when querying the specific course/student pair.
}
```

A `#[MatchTag]` value must be one of the tags shared by the entity and the event. The compiler
rejects unnecessary tags on single-tag events, invalid tags outside that intersection, empty
attributes, and `#[MatchTag]` on methods that are not also `#[EventSourcingHandler]` methods.

## Symfony

Register `Ultra\EventSourcedState\EventSourcedStateBundle` to autoconfigure `#[EventSourcedEntity]` services and compile entity loaders.
