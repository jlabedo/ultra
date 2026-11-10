# Unit-of-work Symfony integration

[UnitOfWorkExtension](../../src/UnitOfWork/Runtime/UnitOfWorkExtension.php) wires services directly from the UnitOfWork package.

The extension registers the composite unit of work, the transaction manager and UnitOfWorkRunner. For runner behavior, nested failures and flush ordering, read the [transaction contract](README.md).

EventStore integration is optional. When its package is installed and an `EventStore` service is declared,
the extension decorates it with `EventStoreUnitOfWork` and includes it in the composite unit of work.
Symfony resolves this optional decoration during compilation, so the service can be declared as a definition
or alias before or after the extension is built. Without that service, the decorator is removed and other
unit-of-work participants still flush and clear normally.

When MessageHandlingExtension is installed, the runner's around advice wraps compiled handlers. A read-only handler opens no transaction unless the unit of work reports pending work. The extension defaults to NoTransactionManager if no TransactionManager service exists. Configure a real transaction manager for the same connection as participating database resources.

For a runnable application using PostgreSQL event sourcing and a Mapped Table projection,
see the [Symfony event sourcing example](../../examples/symfony-event-sourcing/README.md).
It illustrates command work boundaries, separate projection batches and buffer cleanup after failures.

## Doctrine ORM

ORM participation is opt-in. With DoctrineBundle and `doctrine/orm` installed, register the adapter
as an autoconfigured service so UnitOfWorkExtension collects it in the composite:

```yaml
services:
    Ultra\UnitOfWork\DoctrineUnitOfWork:
        autoconfigure: true
        arguments:
            $registry: '@doctrine'
            $managerName: null

    Ultra\UnitOfWork\DbalTransactionManager:
        arguments:
            $connection: '@doctrine.dbal.default_connection'

    Ultra\UnitOfWork\TransactionManager:
        alias: Ultra\UnitOfWork\DbalTransactionManager
```

For a named ORM manager, set `$managerName` and select its matching DBAL connection. Use a normal
service tag with a priority when its flush must precede or follow other participants:

```yaml
tags:
    - { name: ultra_unit_of_work.unit_of_work, priority: 100 }
```

The adapter resolves the current manager through the registry on each flush and clear. It does
not reset the manager itself. The [ORM participation contract](README.md#doctrine-orm-participation)
defines transaction, cleanup, failure and manager-replacement responsibilities.
