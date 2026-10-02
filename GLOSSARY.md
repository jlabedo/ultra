# Ultra

Ultra provides composable application building blocks. Message handling is the primary application-facing API, while many building blocks can also be used independently.

## Language

**State**:
A representation of the information needed for a decision or a read. A state does not necessarily represent a complete domain entity or an aggregate root.
_Avoid_: Aggregate as a synonym for state.

**Event-sourced state**:
A state reconstructed from the history of relevant events.

**Stored state**:
A state represented by its persisted current values.

**Consistency boundary**:
The information whose concurrent modification must invalidate a decision. It can vary from one decision to another and can include dependencies beyond the states declared in the handler signature.

**Stream**:
An ordered, filtered sequence of events. Filters currently select events by domain identifiers and event names.
