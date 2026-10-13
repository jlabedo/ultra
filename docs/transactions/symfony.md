# Unit-of-work Symfony integration

[UnitOfWorkExtension](../../src/UnitOfWork/Runtime/UnitOfWorkExtension.php) wires services directly from the UnitOfWork package.

The extension registers the composite unit of work, the transaction manager and UnitOfWorkRunner. For runner behavior, nested failures and flush ordering, read the [transaction contract](README.md).

EventStore integration is optional. When its package is installed and an `EventStore` service is declared,
the extension decorates it with `EventStoreUnitOfWork` and includes it in the composite unit of work.
Symfony resolves this optional decoration during compilation, so the service can be declared as a definition
or alias before or after the extension is built. Without that service, the decorator is removed and other
unit-of-work participants still flush and clear normally.

When MessageHandlingExtension is installed, the runner's around advice wraps compiled handlers. A read-only handler opens no transaction unless the unit of work reports pending work. The extension defaults to NoTransactionManager if no TransactionManager service exists. Configure a real transaction manager for the same connection as participating database resources.
