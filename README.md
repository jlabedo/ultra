# Ultra

Ultra is a private PHP monorepo for exploring low-level application framework building blocks.

The repository is not a published framework and is not currently intended for public use. It is a research and implementation space for infrastructure that may later become standalone packages, feed another framework, or be reshaped before any public release.

## Design principles

### Low-level building blocks

Ultra favors small, composable primitives over a single high-level framework surface. Packages are designed around explicit contracts for message handling, mapping, event storage, state loading, projections, schemas, and units of work.

The goal is to make the core pieces independently understandable and reusable, so higher-level application patterns can be assembled without hiding the underlying mechanics.

Message handling is the primary API for developers using Ultra in their applications. Many building blocks can also be used independently. See the [glossary](GLOSSARY.md) for shared terminology.

### High performance by design

Runtime paths should stay predictable and allocation-conscious. The codebase prefers typed objects, explicit registries, precomputed metadata, deterministic flush ordering, and narrowly scoped adapters over broad runtime introspection.

Performance is treated as an architectural concern, not a final optimization pass.

### Code generation where it clarifies the runtime

Ultra uses compiler packages to move expensive discovery and wiring work out of the hot path. Generated normalizers, routers, wrappers, and loaders should make runtime behavior faster while keeping the generated code inspectable and type-aware.

Code generation is not used to hide complexity. It is used when it makes the runtime model more explicit, faster, or easier to debug.

Compilation complexity is an accepted trade-off for moving work out of runtime execution. Runtime PHP reflection is a design smell when it can be moved to compilation. Where practical, generated code is preferred over metadata interpreted at runtime; Mapper's compiled normalizers exemplify this approach. See the [architectural decision](docs/adr/0002-prefer-compiled-runtime-code.md).

### Explicit boundaries

Packages are kept aligned around package-level responsibilities. Infrastructure concerns such as storage, projection, mapping, mediation, and schema reconciliation are intentionally separated so they can evolve independently.

Symfony integration is provided through bundle packages where useful, but the core abstractions are intended to remain usable outside Symfony.

Symfony is the reference integration for assembling and compiling these building
blocks. Independent use remains possible, with the caller providing the necessary
registries, generated implementations and wiring.

### Experiments are allowed

Backward compatibility is not currently required. APIs may change while the concepts are being tested. The repository should prefer coherent cleanup over preserving early design choices that no longer fit.

## Package map

See [documentation by context](docs/README.md) for concepts, usage and architectural decisions across package boundaries.

- `CodeGeneration`: Shared PHP code-generation helpers.
- `Container`: Container-extension contract shared by package integrations.
- `Coordination`: Experimental autonomous primitives for dirty-key tracking, coordinator leases and wake signals.
- `Coordination_Postgres`: Postgres implementations of the coordination primitives.
- `EventSourcedState`: Event-sourced state loader runtime; maps stored events to object state and owns its Symfony bundle wiring.
- `EventSourcedState_Compiler`: Compiler support that analyzes event-sourced entities and generates typed entity loaders.
- `EventStore`: Core event store contracts, stream queries, stored-event types, and optimistic concurrency primitives.
- `EventStore_Postgres`: Postgres-backed event store implementation.
- `Events`: Event metadata and registry support for mapping event names, classes, and domain identifiers.
- `MappedTable`: Adapter-backed mapped row-set unit of work with in-memory and Postgres adapters.
- `Mapper`: Runtime normalization and denormalization API centered on `UltraNormalizer`.
- `Mapper_Bundle`: Symfony bundle wiring the mapper, runtime normalizers, and generated normalizer support.
- `Mapper_Compiler`: Code generation for high-performance normalizers.
- `MessageHandling`: Core mediator and event-bus abstractions, message context, advice attributes, async primitives, and Symfony bundle wiring.
- `MessageHandling_Compiler`: Compiler support that generates routers and handler wrappers from handler and advice metadata.
- `MessageHandling_PostgresQueue`: Postgres-backed async queue implementation for message-handling dispatch.
- `Projection`: Projection definitions and plans for applying stored events to read models.
- `Projection_Postgres`: Postgres projection runtime, including state tracking, runners, and partition support.
- `Schema`: Schema definition, reconciliation, and Postgres SQL generation primitives.
- `StatefulMessageHandling`: Stateful message-handling layer built on the mediator; loads state parameters, invokes handlers, and saves emitted state changes.
- `StateStore`: Mapper-backed object state loading and saving on top of mapped row sets.
- `StateStore_Bundle`: Symfony bundle wiring for state-store metadata and registries.
- `Time`: Clock, date point, duration, system clock, and static test clock primitives.
- `UnitOfWork`: Minimal unit-of-work contract plus deterministic composite flushing.
- `SymfonyBundle`: Reference Symfony integration that assembles the available package extensions.

Shared test and support utilities live under `src/Testing`; this directory is autoloaded by the root package but is not currently listed as a standalone Composer package.

## Requirements

The root `composer.json` is the source of truth for runtime and tooling requirements. The current codebase requires PHP `>=8.5`.

## Development

Install dependencies with Composer:

```bash
composer install
```

Run the test suite:

```bash
composer test
```

Launch the test Postgres database with Docker Compose:

```bash
docker compose up
```

Postgres integration tests use `ULTRA_TEST_POSTGRES_DSN`. The root `composer test` script sets it to `pgsql://ultra:ultra@127.0.0.1:55432/ultra_test`, which matches the database started by `docker compose up`.

## License

Ultra is private and proprietary for now. No license for public use, distribution, or modification is currently granted.

The license may be changed later before any public release.
