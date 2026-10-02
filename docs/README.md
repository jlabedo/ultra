# Ultra documentation

Ultra is a set of composable building blocks guided by runtime performance. Message handling is the main application-facing API. Documentation is grouped by context, which may span several Composer packages.

## Contexts

- [Message handling](message-handling/README.md): routing, decisions, context and asynchronous delivery.
- [Event sourcing](event-sourcing/README.md): event streams, state reconstruction and Postgres persistence.
- [Stored state](stored-state/README.md): current-value persistence, collections and optimistic expectations.
- [Projections](projections/README.md): partitioned read-model updates and their coordination.
- [Mapping](mapping/README.md): object normalization and compiled normalizers.
- [Schema](schema/README.md): schema definitions, reconciliation and SQL generation.
- [Coordination](coordination/README.md): experimental dirty-key tracking, leases and wake signals.
- [Transactions](transactions/README.md): ordered unit-of-work flush and transaction boundaries.

## Shared references

- [Glossary](../GLOSSARY.md)
- [Architectural decisions](adr/README.md)
- [Planned work](../TODO.md)

Symfony is the reference integration. [UltraBundle](../src/SymfonyBundle/UltraBundle.php) assembles package extensions; Container provides their shared contract. CodeGeneration provides compiler helpers, and Time supplies clock and duration primitives. These support packages do not introduce separate application contexts here.
