# Development and verification

## Setup

Read root [composer.json](../composer.json) for runtime requirements and scripts. Run `composer install`; its tooling bootstrap installs the development tools used by the wrappers in `bin/`.

For Postgres integration, start the database defined by [docker-compose.yml](../docker-compose.yml) with `docker compose up -d`.

## Tests

Run [PHPUnit](../bin/phpunit) from the repository root ([configuration](../phpunit.xml)):

```bash
bin/phpunit
bin/phpunit --coverage-text
bin/phpunit src/StoredState/Tests
```

Postgres tests use the local database through DBAL's `pdo_pgsql` driver by default; PHP must have `pdo_pgsql` enabled.
Set `ULTRA_TEST_POSTGRES_DSN` to use another database or driver. An explicit `pgsql://` DSN keeps the native `pgsql` driver.

[`PostgresConnectionSupport`](../src/Testing/Postgres/PostgresConnectionSupport.php), provided by
[`ultra/testing-postgres`](../src/Testing/Postgres/composer.json), owns PostgreSQL connection creation,
sharing and reset independently of storage fixtures. Examples install it as a Composer development dependency.
`sharedConnection($dsn)` reuses the primary session.
Pass a name as the second argument
to reuse a separate session for a concurrent actor; names identify independent connections within each DSN.
Call `resetSharedConnection()` in `finally` to roll back nested transactions and release session advisory locks.
Fixtures restore session settings they change, such as `search_path` or `lock_timeout`.
Shared connections close at process shutdown. `CooperativeLockingTest` reuses a named competing session and
bounds expected lock waits with a transaction-local `lock_timeout` of 10 ms, after the holder has acquired its locks.
For isolated-schema setup with two shared sessions, see [IAM example verification](../examples/iam/README.md#exécution).

### Timing audit

After setup, run the executable audit from any directory:

```bash
bin/phpunit-audit
bin/phpunit-audit --runs=5 --top=30
bin/phpunit-audit --runs=1 --top=10 -- src/StoredState/Tests
bin/phpunit-audit -v
bin/phpunit-audit --no-ansi > var/phpunit-audit.txt
```

The audit uses Symfony Process to run the existing `bin/phpunit` wrapper from the repository root three times by default, without a process timeout. It preserves the wrapper's environment, PHPUnit configuration and PAO. The default test selection and excluded groups come from [phpunit.xml](../phpunit.xml). Arguments after `--` are passed to PHPUnit.

Symfony Console renders the report on stdout with styled summaries and aligned tables of runs, slowest methods and functional contexts. Long method names and source locations wrap to fit the terminal. Use `--no-ansi` to save plain text as shown above. Progress goes to stderr; raw PHPUnit/PAO output is streamed there with `-v` and shown on unsuccessful runs. Symfony Console also provides `--help`, verbosity and color options.

The audit's subprocess-based functional tests belong to `slow`; verify them explicitly with `bin/phpunit --group=slow src/Testing/Tests/PhpunitAuditTest.php`.

Each passage writes a temporary JUnit report, removed after collection. Command durations include process startup, test discovery and JUnit logging; dependency installation is excluded by requiring installed dependencies before starting. PHPUnit's individual case durations include `setUp` and `tearDown`, but exclude work such as data-provider evaluation and class-level hooks. The report also shows the median difference between command duration and summed case durations.

The top 20 methods are ranked by the median of their total case duration per passage, combining all datasets before taking the median. The report includes the case count, the median of each passage's slowest case, failures/skips and variation. A spread greater than 20% of the median is flagged; it is a noise indicator, not a performance budget. Nonzero PHPUnit exit statuses are retained, and changes to the case selection between passages stop the audit rather than producing an inconsistent ranking.

Functional context totals sum these method medians; percentages refer to that sum, not the total command duration. Attribution uses the context directory under `src/`, following [documentation routing](README.md#task-routing). All package roles and test subdirectories within a context share that attribution. The root mediator and mapper applications belong to their corresponding contexts; IAM has a separate integration category. New contexts without an attribution appear as `Unmapped` until the audit's mapping is updated.

For mutation testing:

```bash
bin/infection --filter=src/StoredState/MappedTable --test-framework-extra-args=src/StoredState/Tests/MappedTable
```

When covering `CooperativeLockingTest`, use `--threads=1`: its query guard names share database-wide advisory
locks, even across isolated schemas. Run `--noop` with the same selection and settings before comparing results;
all unchanged mutants must survive. Concurrent runs against that database can produce false detections.

Report skipped coverage when the change requires database verification. Example applications may have their own Composer setup and test configuration; follow their local instructions.

## Refactoring tests

Use the owning context's guarantees to organize suites and name cases by observable outcomes. For each change, find the existing scenario protecting its guarantee. Extend that case or its data provider when the arrangement and assertions fit; add a case or suite for a distinct guarantee that remains unprotected. When a feature spans guarantees, distribute its cases among their existing owners: deletion can affect buffering, observations, collection ownership and query guards. Review nearby cases for duplication as part of the same change, and extend their shared fixture instead of creating a parallel setup path.

Exercise behavior through the caller's interface. Reserve implementation-detail coverage for complex logic or hard-to-reproduce failures. Share setup and cleanup, keeping assertions in the suites. Verify simple fixture changes through the functional suites or a disposable probe; PostgreSQL's own DDL atomicity does not need a context guarantee suite.

For concurrency, establish the critical state before releasing the competitor. An exclusion test can acquire the holder's lock, check a bounded `lock_timeout` in the competing transaction, then retry after commit. To verify that an in-flight request resumes correctly, observe it waiting on the held lock and check that same request after release. Name the case for the guarantee it demonstrates; a readiness barrier alone can still allow sequential execution.

Before deleting a case, identify the remaining scenario protecting its guarantee and compare covered source lines and detection of the same mutants on unchanged production code. Investigate surviving mutants before adding cases. Verify claimed regression tests against the previous implementation or a deliberately broken variant; a performance refactor can preserve an existing guarantee without introducing another regression case.

Give independent generated compilations distinct class identities; reserve method-level process isolation for cases that need a fresh interpreter. Use concurrent workers only when the scenario requires requests in flight together, and keep these cases in the `slow` group.

## Documentation changes

Verify relative file links and section anchors in changed pages and their incoming references. Run `git diff --check`. A documentation-only change does not require executing the application suite unless it changes executable examples or claims that need runtime verification.
