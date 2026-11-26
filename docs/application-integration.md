# Integrating Ultra in an application

Use this guide when building an application that depends on Ultra. The [contributor instructions](../AGENTS.md) apply when changing Ultra itself. API contracts and caller responsibilities live in the [documentation routing table](README.md#task-routing).

## Find the matching documentation

1. For a new application, follow [installation in an application](development.md#installing-in-an-application).
2. In an existing application, find `ultra/ultra` in `composer.lock` and record its `source.reference`. With the current monorepo installation, this is the commit shared by all Ultra components; the root manifest's `replace` entries account for the component package names.
3. Locate the installed package. With Composer's default vendor directory, its documentation index is `vendor/ultra/ultra/docs/README.md`. If the application customizes `vendor-dir`, use that directory instead. Read the installed documentation and source together. For a locally modified dependency or a path repository, use that checkout and account for its uncommitted changes.
4. If the local documentation is unavailable, fetch the index from GitHub at the recorded commit. Follow links within the same revision:

   ```text
   https://github.com/jlabedo/ultra/blob/<source.reference>/docs/README.md
   https://raw.githubusercontent.com/jlabedo/ultra/<source.reference>/docs/README.md
   ```

   Replace `<source.reference>` with the value from `composer.lock`. The raw URL returns Markdown directly. If the locked revision predates this guide, use its `docs/README.md` as the entry point.

Ultra's development branch can change without backward compatibility guarantees. Use documentation from the installed revision when implementing application code. When evaluating an upgrade, compare the installed and target revisions' affected contracts.

## Choose an integration path

For a first working integration, start with the [getting-started application](../examples/getting-started/README.md). Its guides cover [Symfony assembly](symfony.md), [StateStore](stored-state/getting-started.md), [Mapper](mapping/getting-started.md), [message dispatch](message-handling/getting-started.md), [PostgreSQL EventStore](event-sourcing/getting-started.md), [async delivery](message-handling/async-setup.md) and [projections](projections/getting-started.md). Each guide identifies configuration, commands and expected results.

Use the routing table for the complete set of contexts. These existing guides and runnable examples provide starting points for common application tasks; read their setup instructions at the matching revision before running them.

| Application task | Contract or integration guide | Runnable example |
| --- | --- | --- |
| Handle commands with event-sourced decision state | [Stateful contracts](message-handling/stateful.md), then [decision bindings](message-handling/decisions.md) | [Terms of Use](../examples/terms-of-use/README.md) |
| Persist relational state and collections | [StateStore](stored-state/README.md), then [Symfony integration](stored-state/symfony.md) when using Symfony | [Terms of Use with StateStore](../examples/terms-of-use-state-store/README.md) |
| Compose StateStore, direct SQL reads and transactional writes without Symfony | [StateStore](stored-state/README.md), [query guards](stored-state/query-guards.md) and [transactions](transactions/README.md) | [IAM](../examples/iam/README.md) |
| Inspect mapped row-set plans and PostgreSQL writes | [Mapped Table SQL inspection](stored-state/mapped-table.md#sql-inspection) | [Mapped Table SQL scenarios](../examples/mapped-table-sql/README.md) |

For projections, start with the [projection contract](projections/README.md), including its [Symfony integration](projections/README.md#symfony-integration) when applicable. For normalization, start with the [Mapper contract](mapping/README.md). Context pages link to their guarantee suites for concrete API usage and boundary cases.

Examples carry their own domain choices. Adapt those choices to the consuming application's requirements while preserving the caller responsibilities in the linked contracts.

## Optional agent instructions

Copy the following section into the application's `AGENTS.md` or the instruction file your agent uses. Adjust the local dependency path if needed. Keep it alongside the application's own setup and verification instructions.

```markdown
## Using Ultra

When adding or changing code that uses Ultra:

1. Read `vendor/ultra/ultra/docs/README.md` and select the contracts for the task.
   For a custom Composer vendor directory, adjust this path. If local docs are
   unavailable, find `ultra/ultra`'s `source.reference` in `composer.lock` and read
   `https://raw.githubusercontent.com/jlabedo/ultra/<source.reference>/docs/README.md`
   at that commit, following its links within the same revision.
2. Read the selected contracts and their linked integration or example pages.
   Follow related context links when the task crosses persistence, transaction,
   compilation or delivery boundaries.
3. Check API names and signatures against the installed source. Use the context's
   guarantee tests to resolve ambiguity. Treat TODO entries as planned work and
   ADRs as rationale; use current contracts, implementation and tests for behavior.
4. Implement the application integration and verify it with this application's
   tests, including the caller responsibilities identified in the contracts.

For an Ultra upgrade, review the affected contracts at both the installed and
target revisions before adapting application code.
```

An instruction file in the consuming application provides an explicit route to Ultra's documentation. Link to the contracts rather than copying them into that file, so the agent loads only the relevant branches and reads the revision that matches the dependency.
