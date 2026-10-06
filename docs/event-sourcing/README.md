# Event sourcing

This context spans Events, EventStore, EventStore_Postgres, EventSourcedState and EventSourcedState_Compiler. Events supplies event names and domain identifiers; EventStore stores and selects events, while EventSourcedState reconstructs decision-specific states. Generated loaders move state discovery out of the runtime path.

- [Event store and stream queries](event-store.md)
- [Event-sourced states and loaders](states.md)
- [Postgres persistence](postgres.md)
- [Decision-specific states: architectural rationale](../adr/0001-decision-specific-states.md)

## Test ownership

The context's suites live in `src/EventStore/Tests`, including its compiler and Postgres guarantees.

| Guarantee | Suite |
| --- | --- |
| Event names identify one class and retain every domain identifier | [EventIdentityTest](../../src/EventStore/Tests/EventIdentityTest.php) |
| Query branches select events once, with AND identifiers and branch-local OR types | [EventSelectionTest](../../src/EventStore/Tests/EventSelectionTest.php) |
| Appended values and batch order survive storage | [EventValuePersistenceTest](../../src/EventStore/Tests/EventValuePersistenceTest.php) |
| Checkpoints preserve observed gaps, catch-up pages and bounded replay | [CheckpointReplayTest](../../src/EventStore/Tests/CheckpointReplayTest.php) |
| Every append condition guards its exact query before insertion | [OptimisticAppendTest](../../src/EventStore/Tests/OptimisticAppendTest.php) |
| Pending reads, persisted checkpoints, ordered flush and clear retain separate responsibilities | [BufferedEventsTest](../../src/EventStore/Tests/BufferedEventsTest.php) |
| Postgres appends join caller transactions and roll back their own failures atomically | [AppendTransactionTest](../../src/EventStore/Tests/AppendTransactionTest.php) |
| Lock namespaces cover typed and wildcard identifier subsets | [LockNamespaceTest](../../src/EventStore/Tests/LockNamespaceTest.php) |
| Competing guarded appends serialize, while independent writes and late commits remain correct | [ConcurrentAppendTest](../../src/EventStore/Tests/ConcurrentAppendTest.php) |
| Generated loaders bind each entity tag once and reuse alias values | [StateTagBindingTest](../../src/EventStore/Tests/StateTagBindingTest.php) |
| Invalid handlers and alias declarations fail during compilation | [HandlerDeclarationTest](../../src/EventStore/Tests/HandlerDeclarationTest.php) |
| Generated loaders reconstruct decision states from matching events in order | [StateReconstructionTest](../../src/EventStore/Tests/StateReconstructionTest.php) |

Add a case to its owning guarantee. Storage cases use a memory/HStore/query-streams provider; buffer-only
rules use memory, while transaction and race cases use real Postgres connections. Concurrency cases retain
the `slow` and `event-store-concurrency` groups. Lock-namespace tests cover the subset algorithm without
asserting SQL text or hash values.

Support contains setup and observations, with assertions in the suites. The fixtures under
`EventSourcedState/Tests` keep their existing paths for StatefulMessageHandling consumers. See
[development verification](../development.md#tests) and the [refactoring checks](../development.md#refactoring-tests).
