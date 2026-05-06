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

## Symfony

Register `Ultra\EventSourcedState\EventSourcedStateBundle` to autoconfigure `#[EventSourcedEntity]` services and compile entity loaders.
