# Development and verification

## Installing in an application

Ultra requires PHP 8.5 or later when installed as the complete monorepo. Git must be available during dependency installation. Add its GitHub repository to the consuming application's root `composer.json`:

```json
{
    "repositories": [
        {
            "type": "git",
            "url": "https://github.com/jlabedo/ultra.git"
        }
    ],
    "require": {
        "ultra/ultra": "dev-main"
    }
}
```

Merge these entries into the application's existing configuration, then run `composer update ultra/ultra --with-all-dependencies`. Commit the application's `composer.lock` to pin the installed commit. Review updates against the affected context contracts: the development branch has no backward compatibility guarantee.

This installs the root package, including all building blocks and their runtime dependencies. Composer reads the repository's root manifest; it does not discover the packages under `src/` as separate VCS packages. The root package replaces their Composer names so other dependencies can require those components without installing duplicate copies. Individual package distribution and versioned releases are future work.

Declare the VCS repository in each consuming application's root manifest. It takes precedence over Packagist by default, where the name `ultra/ultra` is already used by an unrelated project. Composer does not inherit repository declarations from dependencies. See [Composer's VCS repository documentation](https://getcomposer.org/doc/05-repositories.md#vcs).

The explicit `git` driver fetches the public repository over HTTPS without GitHub credentials or API metadata requests. Composer's automatic `vcs` driver uses the GitHub API and can request authentication when its rate limit is reached. This setting applies to Ultra; other dependencies keep their own download mechanisms. Ultra's development dependencies and development autoload mappings are not enabled, and its Composer scripts are not executed, when it is installed as an application dependency.

## Setup

Read root [composer.json](../composer.json) for runtime requirements and scripts. Run `composer install`; its tooling bootstrap installs the development tools used by the wrappers in `bin/`.

The versioned [tools/composer.lock](../tools/composer.lock) pins development tools and their dependencies for local and CI installations. To update them, run `composer update --working-dir=tools` and commit the updated lock file.

For Postgres integration, start the database defined by [docker-compose.yml](../docker-compose.yml) with `docker compose up -d`.

## Continuous integration

[GitHub Actions](../.github/workflows/checks.yml) runs Mago's formatting, lint and
analysis checks, plus the default PHPUnit suites and the `slow` group, on pushes
and pull requests. Test runs use PHP 8.5 and PostgreSQL 18, with the test DSN set
explicitly; skipped tests fail the job.

## Benchmarks

After setup, run [bin/phpbench](../bin/phpbench). [phpbench.json](../phpbench.json) owns runner settings;
`bin/phpbench run --help` lists selection and reporting options. For EventStore scenarios, dataset overrides
and GIN maintenance comparisons, read [Postgres workloads](event-sourcing/postgres.md#benchmarks).

```bash
docker compose up -d
bin/phpbench run --report=aggregate
bin/phpbench run --filter=benchReplay --variant='^hstore/shared-exclusive/' --report=aggregate
bin/phpbench run --report=aggregate --tag=baseline
bin/phpbench run --report=aggregate --ref=baseline
```

For a baseline comparison, keep workload, warmup, revolutions and environment identical. Tags use letters,
digits, dots or underscores. Parameter-set names include a configuration fingerprint: changed datasets or
drivers produce separate variants. Check PHP, DBAL and PostgreSQL environment metadata in the saved results;
server settings and repetition counts are outside that fingerprint. Validate every selected subject before
interpreting its timing, and inspect individual iterations when dispersion is large relative to the difference.

The aggregate report's `mode` estimates the mode of iteration averages; `rstdev` describes their dispersion.
For operation latency percentiles, collect many iterations with `--revs=1`. Memory peaks include setup and
reference data; timed calls exclude setup, validation and cleanup. Use a stable measurement host for performance
budgets and the context's guarantee suites for correctness.

## Mago

The repository's [Mago wrapper](../bin/mago) uses [mago.toml](../mago.toml). Run it from the repository root:

```bash
bin/mago format --check
bin/mago lint
bin/mago analyze
bin/mago lint src/StoredState/StateStore/StateStore.php
bin/mago config --show linter
bin/mago list-files --command analyzer
```

The first run requires network access to download the platform binary. For download failures, see
the [Composer installation guide](https://mago.carthage.software/latest/en/guide/installation/#composer).

Source selection covers `src/`, `tests/`, `examples/*/src/**/*.php` and `examples/*/tests/**/*.php`.
Analysis loads dependency metadata from `vendor/` and `tools/vendor/phpunit/`, where the tooling bootstrap
installs PHPUnit. For progressive adoption, the analyzer excludes `src/*/Tests/**`, `tests/**` and `examples/**`.
The linter excludes test scenarios, fixtures and integration applications under `src/*/Tests/**`, `tests/**`
and `examples/*/tests/**`; example sources remain linted. All selected files remain formatted. Shared
infrastructure in `src/Testing/` outside its `Tests/` directory remains analyzed and linted.
The configuration disables selected stylistic rules and the linter's `cyclomatic-complexity`, `kan-defect`,
`too-many-methods`, `excessive-parameter-list` and `halstead` metrics to avoid broad structural refactoring during
adoption. The `no-else-clause` and `no-boolean-flag-parameter` rules are disabled: alternatives with shared
continuation and boolean domain facts do not require structural changes solely to satisfy lint advice.
Analyzer checks such as unused definitions, dead code and exception declarations are disabled as well.
The analyzer ignores `mixed-assignment` because validation and dynamic dispatch
boundaries legitimately assign values before their types are known. Other diagnostics involving `mixed`,
concrete type errors and unresolved symbols remain reported. There is no baseline suppressing existing issues,
so remaining analysis errors still fail the command.

Example applications have overlapping `App` namespaces and separate dependency sets; their type analysis
requires an application-specific configuration.

PHPDoc must describe stored values as well as accepted inputs. PHP converts numeric string array keys to integers;
use `array-key` for maps that can contain such keys so analysis preserves necessary string conversions.
Validation boundaries take unvalidated inputs and describe the narrower type only after validation. Review automatic
analysis fixes against the guarantee suites before retaining them.

Use `bin/mago format` to apply formatting with the same configuration.
Leave formatting-only changes to CI, as required by the [contributor rules](../AGENTS.md).

## Tests

Run [PHPUnit](../bin/phpunit) from the repository root ([configuration](../phpunit.xml)):

```bash
bin/phpunit
bin/phpunit --coverage-text
bin/phpunit src/StoredState/Tests
```

The complete package's installation guarantee is owned by [ComposerConsumerTest](../src/Testing/Tests/ComposerConsumerTest.php). It installs Ultra in a separate application without development dependencies and exercises mapper compilation, Symfony assembly, stores, projection commands and coordination. The test belongs to `slow`, requires Composer on `PATH` and network access to resolve its public dependencies. Run it with `bin/phpunit --group slow src/Testing/Tests/ComposerConsumerTest.php`.

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

For opt-in SQL observations, `PostgresConnectionSupport::connection($dsn, $configuration)` accepts DBAL
configuration before the driver is created. `SqlTrace` and `SqlTraceMiddleware` collect driver SQL,
parameters, types, durations, transaction calls and failures. See the
[standalone SQL inspection example](../examples/mapped-table-sql/README.md#reuse-the-collector) for setup and
measurement limits. Shared connections retain their existing assembly and are not retroactively instrumented.

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

Exercise behavior through the caller's interface. Reserve implementation-detail coverage for complex logic or hard-to-reproduce failures. Share setup and cleanup, keeping assertions in the suites. Verify simple fixture changes and performance-only refactors through existing functional suites or a disposable probe; PostgreSQL's own DDL atomicity does not need a context guarantee suite.

For concurrency, establish the critical state before releasing the competitor. An exclusion test can acquire the holder's lock, check a bounded `lock_timeout` in the competing transaction, then retry after commit. To verify that an in-flight request resumes correctly, observe it waiting on the held lock and check that same request after release. Name the case for the guarantee it demonstrates; a readiness barrier alone can still allow sequential execution.

Before deleting a case, identify the remaining scenario protecting its guarantee and compare covered source lines and detection of the same mutants on unchanged production code. Investigate surviving mutants before adding cases. Verify claimed regression tests against the previous implementation or a deliberately broken variant; a performance refactor can preserve an existing guarantee without introducing another regression case.

Give independent generated compilations distinct class identities; reserve method-level process isolation for cases that need a fresh interpreter. Use concurrent workers only when the scenario requires requests in flight together, and keep these cases in the `slow` group.

## Reviewing changes

Review against the [contributor rules](../AGENTS.md) and the affected [context contracts](README.md#task-routing). Assess supporting edits using the [test workflow](#refactoring-tests) and [documentation rules](#documentation-changes); retain changes that make the requested behavior easier to understand or verify.

## Documentation changes

Update the owning context page when a guarantee or caller responsibility changes. Keep local fixture and harness implementation details in code when they do not change that contract.

Verify relative file links and section anchors in changed pages and their incoming references. Run `git diff --check`. A documentation-only change does not require executing the application suite unless it changes executable examples or claims that need runtime verification.
