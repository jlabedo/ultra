# Ultra Framework

A modern PHP framework built with PHP 8.4+.

## Components

This is a monorepo containing the following packages:

- **ultra/aop** - Aspect Oriented Programming component
- **ultra/serializer** - Serialization component
- **ultra/orm** - Object Relation Mapper component

## Requirements

- PHP 8.4 or higher
- Composer

## Installation

```bash
composer install
```

## Development

### Running Tests

Run all tests:
```bash
composer test
```

Run Postgres integration tests against the local development database:
```bash
ULTRA_TEST_POSTGRES_DSN='pgsql://ultra:ultra@127.0.0.1:55432/ultra_test' composer test
```

Run tests with coverage:
```bash
composer test:coverage
```

Run the Postgres event-store benchmark:
```bash
composer bench:event-store-postgres
```

Override the database or benchmark size:
```bash
ULTRA_TEST_POSTGRES_DSN=pgsql://ultra:ultra@127.0.0.1:55432/ultra_test composer bench:event-store-postgres -- --seed-events=50000 --read-iterations=200
```

### Code Style

Fix code style issues:
```bash
composer cs-fix
```

## Monorepo Management

This project uses [Symplify Monorepo Builder](https://github.com/symplify/monorepo-builder) for managing the monorepo structure.

## License

MIT
