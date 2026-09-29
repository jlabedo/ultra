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

## Tag Aliases

A domain identifier has one value per name, so an event that concerns two values of the same
dimension carries them under two names. A reschedule, for example, is tagged with the room day it
moves to (`roomDay`) and the one it leaves (`previousRoomDay`). The schedule of one room day must
see both: bookings arriving and bookings leaving.

An alias is an extra tag name under which the entity also loads events, with the value of the tag
it aliases:

```php
#[EventSourcedEntity(tags: ['roomDay'], aliases: ['previousRoomDay' => 'roomDay'])]
final class RoomDayScheduleState
{
    /** @var array<string, Slot> */
    public private(set) array $bookings = [];

    #[EventSourcingHandler]
    #[MatchTag('roomDay')]
    #[MatchTag('previousRoomDay')]
    public function onRescheduled(BookingRescheduled $event): void
    {
        // Loaded as the new day (roomDay = my id) or as the old day (previousRoomDay = my id).
        // The state does not know its own id, so it infers its role from what it holds.
        if (isset($this->bookings[$event->bookingId]) && $event->roomDayId !== $event->previousRoomDayId) {
            unset($this->bookings[$event->bookingId]); // the old day: the booking leaves
        } else {
            $this->bookings[$event->bookingId] = $event->slot; // the new day, or a move within the same day
        }
    }
}
```

Loading the schedule of `room-1@2026-09-28` queries `roomDay = room-1@2026-09-28` and
`previousRoomDay = room-1@2026-09-28`.

- `aliases` maps an alias name to one of the entity's tags. The compiler rejects an alias whose
  target is not a tag, and an alias named like a tag.
- Aliases take part in matching like tags: an event carrying both `roomDay` and `previousRoomDay`
  is ambiguous and needs `#[MatchTag]`, and `#[MatchTag('previousRoomDay')]` is a valid group. A
  group may combine a tag and an alias: `#[MatchTag('roomDay', 'previousRoomDay')]` only loads
  events whose two values are both the entity's id.
- Aliases do not identify the entity. The loader, `EventSourcedStateStore::load()` and decision
  handlers still take one value per tag; passing an alias name is rejected. A single-tag entity with
  aliases is still a valid decision state, and its append condition covers the alias branches, so a
  concurrent event tagged only with the alias fails the append.

## Symfony

Register `Ultra\EventSourcedState\EventSourcedStateExtension` to autoconfigure `#[EventSourcedEntity]` services and compile entity loaders.
