# Development and verification

## Setup

Read root [composer.json](../composer.json) for runtime requirements and scripts. Run `composer install`; its tooling bootstrap installs the development tools used by the wrappers in `bin/`.

For Postgres integration, start the database defined by [docker-compose.yml](../docker-compose.yml) with `docker compose up -d`.

## Tests

Run [PHPUnit](../bin/phpunit) from the repository root ([configuration](../phpunit.xml)):

```bash
bin/phpunit
bin/phpunit --coverage-text
bin/phpunit src/StateStore/Tests
```

Postgres tests use the local database by default.

### Timing audit

After setup, run the executable audit from any directory:

```bash
bin/phpunit-audit
bin/phpunit-audit --runs=5 --top=30
bin/phpunit-audit --runs=1 --top=10 -- src/StateStore/Tests
bin/phpunit-audit -v
bin/phpunit-audit --no-ansi > var/phpunit-audit.txt
```

The audit uses Symfony Process to run the existing `bin/phpunit` wrapper from the repository root three times by default, without a process timeout. It preserves the wrapper's environment, PHPUnit configuration and PAO. The default root selection includes IAM tests and excludes the `slow` group, as specified in [phpunit.xml](../phpunit.xml). Arguments after `--` are passed to PHPUnit.

Symfony Console renders the report on stdout with styled summaries and aligned tables of runs, slowest methods and functional contexts. Long method names and source locations wrap to fit the terminal. Use `--no-ansi` to save plain text as shown above. Progress goes to stderr; raw PHPUnit/PAO output is streamed there with `-v` and shown on unsuccessful runs. Symfony Console also provides `--help`, verbosity and color options.

The audit's subprocess-based functional tests belong to `slow`; verify them explicitly with `bin/phpunit --group=slow src/Testing/Tests/PhpunitAuditTest.php`.

Each passage writes a temporary JUnit report, removed after collection. Command durations include process startup, test discovery and JUnit logging; dependency installation is excluded by requiring installed dependencies before starting. PHPUnit's individual case durations include `setUp` and `tearDown`, but exclude work such as data-provider evaluation and class-level hooks. The report also shows the median difference between command duration and summed case durations.

The top 20 methods are ranked by the median of their total case duration per passage, combining all datasets before taking the median. The report includes the case count, the median of each passage's slowest case, failures/skips and variation. A spread greater than 20% of the median is flagged; it is a noise indicator, not a performance budget. Nonzero PHPUnit exit statuses are retained, and changes to the case selection between passages stop the audit rather than producing an inconsistent ranking.

Functional context totals sum these method medians; percentages refer to that sum, not the total command duration. Package attribution follows [documentation routing](README.md#task-routing), including compiler and storage adapters. The root mediator and mapper applications belong to their corresponding contexts; IAM has a separate integration category. New packages without an attribution appear as `Unmapped` until the audit's mapping is updated.

For mutation testing:

```bash
bin/infection --filter=src/MappedTable --test-framework-extra-args=src/MappedTable/Tests
```

Report skipped coverage when the change requires database verification. Example applications may have their own Composer setup and test configuration; follow their local instructions.

## Refactoring tests

Use the owning context's guarantees to organize suites and cases. Share setup and cleanup; keep assertions in the suites. Before deleting a case, identify the remaining scenario protecting its guarantee and compare covered source lines and detection of the same mutants on unchanged production code. Investigate surviving mutants before adding cases.

Give independent generated compilations distinct class identities; reserve method-level process isolation for cases that need a fresh interpreter.

## Documentation changes

Verify relative file links and section anchors in changed pages and their incoming references. Run `git diff --check`. A documentation-only change does not require executing the application suite unless it changes executable examples or claims that need runtime verification.
