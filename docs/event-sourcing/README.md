# Event sourcing

This context spans Events, EventStore, EventStore_Postgres, EventSourcedState and EventSourcedState_Compiler. Events supplies event names and domain identifiers; EventStore stores and selects events, while EventSourcedState reconstructs decision-specific states. Generated loaders move state discovery out of the runtime path.

- [Event store and stream queries](event-store.md)
- [Event-sourced states and loaders](states.md)
- [Postgres persistence](postgres.md)
- [Decision-specific states: architectural rationale](../adr/0001-decision-specific-states.md)
