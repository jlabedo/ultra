# AGENTS.md

This file is for coding agents working in the Ultra monorepo.

## Repo overview

- This is a PHP monorepo with multiple packages under `src/`.
- Examples live under `examples/`.
- Additional integration-style example apps live under `tests/`.
- The root `composer.json` is the best source of truth for tooling and runtime requirements.

## Package map

- `StatefulMessageHandling`: Stateful message-handling layer built on the mediator; loads state parameters, invokes handlers, and saves emitted state changes.
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
- `StateStore`: Mapper-backed object state loading and saving on top of mapped row sets.
- `StateStore_Bundle`: Symfony bundle wiring for state-store metadata and registries.
- `Time`: Clock, date point, duration, system clock, and static test clock primitives.
- `UnitOfWork`: Minimal unit-of-work contract plus deterministic composite flushing.
- `UnitOfWork_Bundle`: Symfony bundle wiring for unit-of-work services.

Shared test and support utilities live under `src/Testing`; this directory is autoloaded by the root package but is not currently listed as a standalone Composer package.

## Source of truth

- Prefer the current codebase and root tooling config over README prose when they disagree.
- Example: the root `composer.json` currently requires PHP `>=8.5`, even though the root `README.md` still mentions PHP 8.4+.

## Coding rules

- Backward compatibility is not required in any package. None of these packages are published.
- Opportunistic cleanup is encouraged when touching a file, as long as the change stays coherent and improves the code.
- Do not run `php-cs-fixer` or `composer cs-fix`.
- Do not spend time on formatting-only edits. CI handles formatting.
- Keep edits aligned with the surrounding style and package boundaries.

## Code style already present in the repo

- Files use `declare(strict_types=1);`.
- The codebase uses modern PHP features heavily, including typed properties, `readonly`, attributes, and constructor promotion.
- The test suite is run through Pest, but most current tests are PHPUnit-style class tests.
- Prefer PHPUnit-style class tests for new coverage.
- Docblocks are used where they add value, especially for generics, array shapes, or intent that PHP types cannot express directly.

## Testing expectations

- Every behavior change should include a test addition or test update.
- Tests should focus on functional behavior, not implementation detail.
- Exception: implementation-detail coverage is acceptable for complex logic when it helps debugging or covers hard-to-reproduce edge cases.
- Prefer PHPUnit-style tests for new or updated coverage.
- Follow the surrounding package style when working in older areas that have not migrated yet.
- Put tests next to the package they cover when possible, usually under `src/<Package>/Tests`.

Useful commands:

- `composer test`
- `composer test:coverage`
- `ULTRA_TEST_POSTGRES_DSN='pgsql://ultra:ultra@127.0.0.1:55432/ultra_test' composer test`

Notes about the test suite:

- The root Composer `test` script currently sets `ULTRA_TEST_POSTGRES_DSN` and runs `pest --compact`.
- Postgres integration tests skip themselves when `ULTRA_TEST_POSTGRES_DSN` is not set.

## Change strategy

- Prefer the smallest change that cleanly solves the problem.
- If you touch a file and see obvious nearby cleanup, it is fine to include it.
- Avoid formatting-only churn.
- If a behavioral change affects examples or docs, update them in the same change when practical.
