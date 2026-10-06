# Working in Ultra

Contexts live in `src/<Context>/`, with Composer packages in role directories and shared guarantee suites in `Tests/`. Use [documentation routing](docs/README.md#task-routing) to locate the context. Examples live in `examples/`, and integration applications in `tests/`.

## Before changing code

1. Use [documentation routing](docs/README.md#task-routing) to read the contracts for the affected context. Read only the branches relevant to the change.
2. Check those contracts against the implementation and tests. Code and root tooling configuration are authoritative for current behavior; ADRs record the reasons behind choices, and TODO records future work.
3. When changing terminology, consult [GLOSSARY.md](GLOSSARY.md). When changing architecture or compiler/runtime boundaries, consult the [ADRs](docs/adr/README.md).

## Change rules

- Backward compatibility is not required.
- Keep changes coherent with package responsibilities. Nearby cleanup is welcome when it supports the change.
- Follow the surrounding style. Use docblocks for generics, shapes and intent that PHP types cannot express.
- Do not run `php-cs-fixer` or `composer cs-fix`. Leave formatting-only changes to CI.

## Commit messages

Write commit messages in English so history uses the same vocabulary as code and contracts. Use this subject format:

```text
type(Context): imperative description of the change
```

- Use `feat` for new behavior, `fix` for corrections, `perf` for performance improvements, `refactor` for restructuring with unchanged behavior, `test` for test-only changes, `docs` for documentation-only changes, `build` for dependencies or build tooling, and `ci` for CI configuration. Choose the type from the purpose of the change; a fix with regression tests is still `fix`.
- Use the exact context directory name from `src/`, preserving spelling and case: `StoredState`, `Mapper`, `MessageHandling`, etc. Compiler and integration changes use their owning context (`Mapper` for `Mapper_Compiler` or `Mapper_Bundle`). Use `Testing` for shared test infrastructure and `Repository` for repository-wide contributor rules or tooling. For changes spanning contexts, use the main owning context and name the others in the body; use `Repository` when there is no single owner.
- Describe the changed behavior, guarantee or constraint. Include the relevant code identifier or domain term when it helps retrieval; preserve its exact spelling. Keep the subject readable in `git log --oneline`, moving supporting detail to the body.
- Add a body when the reason or consequences cannot be understood from the subject. Explain the trigger or previous limitation, why the change is needed, and any relevant tradeoff. Name affected symbols, related contexts or contract paths that future agents can search for, and record verification actually performed or why it was not run.
- Before committing, check the message against the staged diff: it must describe the entire change accurately. Split unrelated changes into separate commits.

Examples:

```text
fix(Mapper): isolate generated normalizer identities by compilation namespace
test(MessageHandling): run binding compilation failures in process
perf(StoredState): bound cooperative lock waits in PostgreSQL tests
docs(Repository): define searchable commit messages
```

## Verification

- Every behavior change includes functional test coverage. Implementation-detail coverage is appropriate for complex logic or hard-to-reproduce cases.
- Group each context's guarantee suites under `src/<Context>/Tests`, including its compiler and integration packages; shared support is in `src/Testing`.
- For test additions and refactors, follow the [guarantee-suite workflow](docs/development.md#refactoring-tests).
- For test setup, package-targeted runs or Postgres integration, read [development verification](docs/development.md).
- Before finishing, run checks appropriate to the change and update affected contracts or examples. For documentation changes, verify local links and distinguish current behavior from planned work.

## Maintaining documentation

Use the [reference ownership rules](docs/README.md#reference-ownership) to choose where to write. Update the authoritative page and link to it instead of repeating its contract. Add a routing entry when a new context or task branch needs its own documentation.
