# Event sourcing

This context spans Events, EventStore, EventStore_Postgres, EventSourcedState and EventSourcedState_Compiler. Events supplies event names and domain identifiers; EventStore stores and selects events, while EventSourcedState reconstructs decision-specific states. Generated loaders move state discovery out of the runtime path.

- [Event store and stream queries](event-store.md)
- [Event-sourced states and loaders](states.md)
- [Postgres persistence](postgres.md)
- [Decision-specific states: architectural rationale](../adr/0001-decision-specific-states.md)

Event resource tags must resolve to an existing class and a string event name. Invalid declarations fail
during container compilation with `LogicException`; names default to the event class when omitted.
Registry lookups preserve every string event name, including empty strings and numeric strings.
PHP converts numeric string keys in the registry's `all()` map to integers; reverse lookup returns the string name.

## Test ownership

The context's suites live in `src/EventSourcing/Tests`, including its compiler and Postgres guarantees.

| Guarantee | Suite |
| --- | --- |
| Event names identify one class and retain every domain identifier | [EventIdentityTest](../../src/EventSourcing/Tests/EventIdentityTest.php) |
| Query branches select events once, with AND identifiers and branch-local OR types | [EventSelectionTest](../../src/EventSourcing/Tests/EventSelectionTest.php) |
| Appended values and batch order survive storage | [EventValuePersistenceTest](../../src/EventSourcing/Tests/EventValuePersistenceTest.php) |
| Checkpoints preserve observed gaps, catch-up pages and bounded replay with explicit continuation | [CheckpointReplayTest](../../src/EventSourcing/Tests/CheckpointReplayTest.php) |
| Every append condition guards its exact query before insertion | [OptimisticAppendTest](../../src/EventSourcing/Tests/OptimisticAppendTest.php) |
| Pending events appear on the final catch-up page; persisted checkpoints, ordered flush and clear retain their responsibilities | [BufferedEventsTest](../../src/EventSourcing/Tests/BufferedEventsTest.php) |
| Postgres appends join caller transactions and roll back their own failures atomically | [AppendTransactionTest](../../src/EventSourcing/Tests/AppendTransactionTest.php) |
| Lock namespaces cover typed and wildcard identifier subsets | [LockNamespaceTest](../../src/EventSourcing/Tests/LockNamespaceTest.php) |
| Competing guarded appends serialize, while independent writes and late commits remain correct | [ConcurrentAppendTest](../../src/EventSourcing/Tests/ConcurrentAppendTest.php) |
| Generated loaders bind each entity tag once and reuse alias values | [StateTagBindingTest](../../src/EventSourcing/Tests/StateTagBindingTest.php) |
| Invalid handlers and alias declarations fail during compilation | [HandlerDeclarationTest](../../src/EventSourcing/Tests/HandlerDeclarationTest.php) |
| State loading folds matching history page by page, releases consumed pages and applies pending events once | [StateReconstructionTest](../../src/EventSourcing/Tests/StateReconstructionTest.php) |

Add a case to its owning guarantee. Storage cases use a memory/HStore/query-streams provider; buffer-only
rules use memory, while transaction and race cases use real Postgres connections. Concurrency cases retain
the `slow` and `event-store-concurrency` groups. Lock-namespace tests cover the subset algorithm without
asserting SQL text or hash values.

Support contains setup and observations, with assertions in the suites. The fixtures under
`src/EventSourcing/Tests/State` retain their namespaces for StatefulMessageHandling consumers. See
[development verification](../development.md#tests) and the [refactoring checks](../development.md#refactoring-tests).
