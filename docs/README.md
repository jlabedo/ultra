# Documentation routing

Source code and documentation follow functional contexts. Use the task table to locate the context and select its contract; compiler and integration packages belong to the context they support.

## Task routing

| When working on | Read | Source context | Owning packages |
| --- | --- | --- | --- |
| Routing, advice, handlers or message context | [Message handling](message-handling/README.md) | [MessageHandling](../src/MessageHandling) | MessageHandling, MessageHandling_Compiler |
| Decision state injection, derived IDs or CHECK | [Stateful contracts](message-handling/stateful.md), then [bindings and examples](message-handling/decisions.md) | [MessageHandling](../src/MessageHandling) | StatefulMessageHandling |
| Async dispatch, queue delivery, outbox or deployment compatibility | [Async delivery](message-handling/async.md) | [MessageHandling](../src/MessageHandling) | MessageHandling, MessageHandling_PostgresQueue |
| Event queries, checkpoints, append conditions or state replay | [Event sourcing](event-sourcing/README.md) | [EventSourcing](../src/EventSourcing) | Events, EventStore, EventStore_Postgres, EventSourcedState, EventSourcedState_Compiler |
| Row buffering, row-set reconciliation, mapped keys or storage adapters | [Mapped Table](stored-state/mapped-table.md) | [StoredState](../src/StoredState) | MappedTable |
| Inspecting a row-set plan and its executed PostgreSQL queries | [SQL inspection](stored-state/mapped-table.md#sql-inspection), then [standalone scenarios](../examples/mapped-table-sql/README.md) | [StoredState](../src/StoredState), [Testing](../src/Testing) | MappedTable, ultra/testing-postgres |
| State mapping, collections or optimistic row expectations | [Stored-state contracts](stored-state/README.md), then [mapping](stored-state/mapping.md) | [StoredState](../src/StoredState) | StateStore, StateStore_Bundle, MappedTable |
| Set-based invariants, query observations or cooperative locking | [Query guards](stored-state/query-guards.md) | [StoredState](../src/StoredState) | StateStore, MappedTable |
| Manual SQL expectations or explicit guards | [Manual queries](stored-state/manual-queries.md) | [StoredState](../src/StoredState) | StateStore |
| Projection ordering, claims, checkpoints or scaling | [Projections](projections/README.md) | [Projection](../src/Projection) | Projection, Projection_Postgres |
| Normalization, custom types or generated normalizers | [Mapping](mapping/README.md) | [Mapper](../src/Mapper) | Mapper, Mapper_Compiler, Mapper_Bundle |
| Schema comparison, renames or SQL generation | [Schema](schema/README.md) | [Schema](../src/Schema) | Schema |
| Dirty-key tracking, coordinator leases or wake signals | [Coordination](coordination/README.md) | [Coordination](../src/Coordination) | Coordination, Coordination_Postgres |
| Flush ordering, transaction boundaries or nested work | [Transactions](transactions/README.md) | [UnitOfWork](../src/UnitOfWork) | UnitOfWork |
| Symfony assembly or extension registration | [UltraBundle](../src/Symfony/Bundle/UltraBundle.php), [ContainerExtension](../src/Symfony/Container/ContainerExtension.php), then the affected context's integration page | [Symfony](../src/Symfony) | SymfonyBundle, Container |
| Shared compiler helpers or clock primitives | Current contracts and tests in [CodeGeneration](../src/CodeGeneration) or [Time](../src/Time) | [CodeGeneration](../src/CodeGeneration), [Time](../src/Time) | CodeGeneration, Time |
| Setup, tooling or test execution | [Development](development.md) | [Testing](../src/Testing) | Root tooling, Testing, ultra/testing-postgres |
| CI workflows or service startup | [Continuous integration](development.md#continuous-integration), then [documentation changes](development.md#documentation-changes) | [Testing](../src/Testing) | Root tooling |
| Reviewing changes | [Review workflow](development.md#reviewing-changes), then the affected context contract | Affected context | Affected packages |

## Source layout

`src/<Context>/` is the unit of navigation and verification. Its role directories contain independent Composer packages; `Tests/` owns guarantees across those packages. For example:

```text
src/Mapper/
  Runtime/       # ultra/mapper
  Compiler/      # ultra/mapper-compiler
  Symfony/       # ultra/mapper-bundle
  Tests/         # runtime, compiler and Symfony guarantees
```

Package roles follow the context's responsibilities. EventSourcing includes Events, EventStore, State, Compiler and Postgres; StoredState includes StateStore, MappedTable and Symfony. These are package directories within their owning contexts, not repository-wide technical layers.

Package names, namespaces and dependency declarations remain independent of context directory names. Each package's `composer.json` owns its dependencies and local autoloading. Root [autoload mappings](../composer.json) locate production code and, separately, test namespaces. Example applications discover packages through the path repository `../../src/*/*`.

Guarantee suites and fixtures live outside package directories. A context can use test subdirectories for a distinct family of guarantees or shared setup, such as `MessageHandling/Tests/Stateful` or `StoredState/Tests/MappedTable`. Shared support remains in [Testing](../src/Testing). Contracts stay in `docs/`; use this routing table rather than duplicating them beside the code.

## Reference ownership

- [AGENTS.md](../AGENTS.md) owns contributor rules.
- [Development](development.md) owns setup, verification and review workflows.
- Context pages own current behavior and caller responsibilities; implementation and tests resolve discrepancies.
- [GLOSSARY.md](../GLOSSARY.md) owns canonical vocabulary.
- [ADRs](adr/README.md) own architectural rationale. Read them when changing the relevant boundary, especially [compilation versus runtime](adr/0002-prefer-compiled-runtime-code.md).
- [TODO](../TODO.md) owns planned work. An open direction is not a current guarantee.

Read related contexts when a change crosses their boundary: decisions use persistence contracts, asynchronous delivery uses transaction contracts, and projections use event-store and coordination contracts. Example setup and scenarios remain under `examples/`.
