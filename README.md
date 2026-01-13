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

Run tests with coverage:
```bash
composer test:coverage
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
