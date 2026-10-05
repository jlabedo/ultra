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

For mutation testing:

```bash
bin/infection --filter=src/MappedTable --test-framework-extra-args=src/MappedTable/Tests
```

Report skipped coverage when the change requires database verification. Example applications may have their own Composer setup and test configuration; follow their local instructions.

## Documentation changes

Verify relative file links and section anchors in changed pages and their incoming references. Run `git diff --check`. A documentation-only change does not require executing the application suite unless it changes executable examples or claims that need runtime verification.
