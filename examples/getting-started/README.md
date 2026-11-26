# Getting started with Ultra

This application demonstrates Symfony with PostgreSQL StateStore, compiled normalization, message dispatch and advice, a PostgreSQL event store, durable async delivery and a persisted projection. Each journey has a guide under `docs/`; the sources here are the runnable reference for those guides.

## Setup

Use PHP 8.5 with `pdo_pgsql`, Composer, and PostgreSQL 18 (the repository's verified database version). From the repository root, start the database with `docker compose up -d`, then:

```bash
cd examples/getting-started
composer install
export DATABASE_URL='postgresql://ultra:ultra@127.0.0.1:55432/ultra_test'
php bin/setup.php
```

`setup.php` creates the `tutorial_` tables and event-store lock helpers in one transaction. Run it once on a fresh database/schema. It retains data and fails if the application tables already exist. It is fresh-schema setup, not a migration engine. Review its statements before using another database. PostgreSQL's `PGOPTIONS='-c search_path=your_schema'` can select an existing isolated schema for all commands.

The manifest uses a Composer path repository for this checkout's complete `ultra/ultra` package. To start an application outside the checkout, copy this directory without `vendor/`, `var/` or `composer.lock`, replace its `repositories` entry with the Git repository entry from [installation](../../docs/development.md#installing-in-an-application), and run `composer install`. Keep the application's generated lock file. The [Symfony assembly guide](../../docs/symfony.md) explains the files to adapt.

## Run a journey

Run these commands from this directory, after setup. Mapper and message dispatch do not require the database setup.

| Journey | Command | Guide |
| --- | --- | --- |
| Persist and read a contact through Symfony | `php bin/console app:contact:rename ada Ada` | [StateStore getting started](../../docs/stored-state/getting-started.md) |
| Precompile a normalizer and round-trip a value | `php bin/mapper.php` | [Mapper getting started](../../docs/mapping/getting-started.md) |
| Dispatch a message through advice | `php bin/messages.php` | [MessageHandling getting started](../../docs/message-handling/getting-started.md) |
| Append and load a guarded stream | `php bin/events.php` | [PostgreSQL EventStore getting started](../../docs/event-sourcing/getting-started.md) |
| Commit a contact and queue a notification | `php bin/async.php send` | [Async setup and worker](../../docs/message-handling/async-setup.md) |
| Consume queued notifications in another process | `php bin/async.php consume` | [Async setup and worker](../../docs/message-handling/async-setup.md) |
| Append an event and record projection activity | `php bin/projections.php append` | [Projection getting started](../../docs/projections/getting-started.md) |
| Process pending projection activity | `php bin/projections.php work` | [Projection getting started](../../docs/projections/getting-started.md) |
| Rebuild the read model offline | `php bin/projections.php rebuild` | [Projection getting started](../../docs/projections/getting-started.md) |

Commands leave data in place. Each event append and async send uses a fresh identifier. The EventStore and projection journeys share the `tutorial.course.named` event type: projection counts include events appended by `events.php` as well as `projections.php append`.

## Verification

From the repository root after [development setup](../../docs/development.md#setup):

```bash
bin/phpunit --group=slow src/Testing/Tests/GettingStartedExampleTest.php
```

The test copies the application to a temporary directory, installs its dependencies without development packages, runs the journeys in separate PHP processes, and inspects committed PostgreSQL rows, queue acknowledgement and projection rebuild results. It also forces a notification write failure, verifies that the delivery is released without acknowledgement, and retries after removing the failure. It uses a fresh schema and removes it afterward. It requires Composer, network access and the [test database](../../docs/development.md#tests).
