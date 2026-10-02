# Documentation routing

Documentation follows contexts rather than Composer package boundaries. Use the task table to select the relevant contract; compiler and integration packages belong to the context they support.

## Task routing

| When working on | Read | Owning packages |
| --- | --- | --- |
| Routing, advice, handlers or message context | [Message handling](message-handling/README.md) | MessageHandling, MessageHandling_Compiler |
| Decision state injection, derived IDs or CHECK | [Stateful contracts](message-handling/stateful.md), then [bindings and examples](message-handling/decisions.md) | StatefulMessageHandling |
| Async dispatch, queue delivery, outbox or deployment compatibility | [Async delivery](message-handling/async.md) | MessageHandling, MessageHandling_PostgresQueue |
| Event queries, checkpoints, append conditions or state replay | [Event sourcing](event-sourcing/README.md) | Events, EventStore, EventStore_Postgres, EventSourcedState, EventSourcedState_Compiler |
| State mapping, collections or optimistic row expectations | [Stored-state contracts](stored-state/README.md), then [mapping](stored-state/mapping.md) | StateStore, StateStore_Bundle, MappedTable |
| Set-based invariants, query observations or cooperative locking | [Query guards](stored-state/query-guards.md) | StateStore, MappedTable |
| Manual SQL expectations or explicit guards | [Manual queries](stored-state/manual-queries.md) | StateStore |
| Projection ordering, claims, checkpoints or scaling | [Projections](projections/README.md) | Projection, Projection_Postgres |
| Normalization, custom types or generated normalizers | [Mapping](mapping/README.md) | Mapper, Mapper_Compiler, Mapper_Bundle |
| Schema comparison, renames or SQL generation | [Schema](schema/README.md) | Schema |
| Dirty-key tracking, coordinator leases or wake signals | [Coordination](coordination/README.md) | Coordination, Coordination_Postgres |
| Flush ordering, transaction boundaries or nested work | [Transactions](transactions/README.md) | UnitOfWork |
| Symfony assembly or extension registration | [UltraBundle](../src/SymfonyBundle/UltraBundle.php), [ContainerExtension](../src/Container/ContainerExtension.php), then the affected context's integration page | SymfonyBundle, Container |
| Shared compiler helpers or clock primitives | Current contracts and tests in [CodeGeneration](../src/CodeGeneration) or [Time](../src/Time) | CodeGeneration, Time |
| Setup, tooling or test execution | [Development](development.md) | Root tooling, Testing |

## Reference ownership

- [AGENTS.md](../AGENTS.md) owns contributor rules.
- Context pages own current behavior and caller responsibilities; implementation and tests resolve discrepancies.
- [GLOSSARY.md](../GLOSSARY.md) owns canonical vocabulary.
- [ADRs](adr/README.md) own architectural rationale. Read them when changing the relevant boundary, especially [compilation versus runtime](adr/0002-prefer-compiled-runtime-code.md).
- [TODO](../TODO.md) owns planned work. An open direction is not a current guarantee.

Read related contexts when a change crosses their boundary: decisions use persistence contracts, asynchronous delivery uses transaction contracts, and projections use event-store and coordination contracts. Example setup and scenarios remain under `examples/`.
