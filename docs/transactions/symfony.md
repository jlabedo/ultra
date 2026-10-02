# Unit-of-work Symfony integration

[UnitOfWorkExtension](../../src/UnitOfWork/UnitOfWorkExtension.php) wires services directly from the UnitOfWork package.

The extension registers the composite unit of work, the transaction manager and UnitOfWorkRunner. For runner behavior, nested failures and flush ordering, read the [transaction contract](README.md).

When MessageHandlingExtension is installed, the runner's around advice wraps compiled handlers. A read-only handler opens no transaction unless the unit of work reports pending work. The extension defaults to NoTransactionManager if no TransactionManager service exists. Configure a real transaction manager for the same connection as participating database resources.
