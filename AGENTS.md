# Working in Ultra

Ultra is a private PHP monorepo of composable application building blocks. Message handling is the main application-facing API; runtime performance guides the design. Packages live in `src/`, examples in `examples/`, and integration applications in `tests/`.

## Before changing code

1. Use [documentation routing](docs/README.md#task-routing) to read the contracts for the affected context. Read only the branches relevant to the change.
2. Check those contracts against the implementation and tests. Code and root tooling configuration are authoritative for current behavior; ADRs record the reasons behind choices, and TODO records future work.
3. When changing terminology, consult [GLOSSARY.md](GLOSSARY.md). When changing architecture or compiler/runtime boundaries, consult the [ADRs](docs/adr/README.md).

## Change rules

- Backward compatibility is not required; these packages are unpublished.
- Keep changes coherent with package responsibilities. Nearby cleanup is welcome when it supports the change.
- Follow the surrounding style. Use docblocks for generics, shapes and intent that PHP types cannot express.
- Do not run `php-cs-fixer` or `composer cs-fix`. Leave formatting-only changes to CI.

## Verification

- Every behavior change includes functional test coverage. Implementation-detail coverage is appropriate for complex logic or hard-to-reproduce cases.
- Keep tests alongside the package under `src/<Package>/Tests`; shared support is in `src/Testing`.
- For test setup, package-targeted runs or Postgres integration, read [development verification](docs/development.md).
- Before finishing, run checks appropriate to the change and update affected contracts or examples. For documentation changes, verify local links and distinguish current behavior from planned work.

## Maintaining documentation

Keep contributor rules here, task routing in `docs/README.md`, contracts in their context pages, vocabulary in `GLOSSARY.md`, rationale in ADRs, and planned work in `TODO.md`. Update the authoritative page and link to it instead of repeating its contract. Keep example instructions beside their examples. Add a routing entry when a new context or task branch needs its own documentation.
