# Development and verification

## Setup

Read root [composer.json](../composer.json) for runtime requirements and scripts. Run `composer install`; its tooling bootstrap installs the development tools used by the wrappers in `bin/`.

For Postgres integration, start the database defined by [docker-compose.yml](../docker-compose.yml) with `docker compose up -d`.

## Tests

The root suite uses PHPUnit through [bin/phpunit](../bin/phpunit), configured by [phpunit.xml](../phpunit.xml). Run from the repository root:

```bash
composer test
composer test:coverage
composer test -- src/StateStore/Tests
```

The Composer scripts define the local Postgres URL in [composer.json](../composer.json), so these commands include integration tests without additional environment setup. Arguments after `--` target a package or select tests.

For a different database, invoke `bin/phpunit` directly with the desired `ULTRA_TEST_POSTGRES_DSN`; the Composer scripts override that variable.

Postgres integration tests skip when their required DSN is absent. Report skipped coverage when the change requires database verification. Example applications may have their own Composer setup and test configuration; follow their local instructions.

## Documentation changes

Verify relative file links and section anchors in changed pages and their incoming references. Run `git diff --check`. A documentation-only change does not require executing the application suite unless it changes executable examples or claims that need runtime verification.
