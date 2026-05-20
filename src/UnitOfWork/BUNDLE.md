# Unit Of Work Bundle

Symfony bundle wiring for Ultra unit-of-work services.

## Unit Of Work Runner

The bundle registers `UnitOfWorkRunner` as the user-facing API for application work that should flush the unit of work when it succeeds.

```php
$runner->run(function () use ($mediator, $context): void {
    $mediator->send(new A(), $context);
    $mediator->send(new B(), $context);
});
```

The runner executes the callback, flushes the configured `UnitOfWork` through `TransactionManager` only when pending changes exist, and clears the unit of work afterward. Nested runner calls join the active run; nested exceptions mark the active run rollback-only so swallowed failures cannot later commit buffered writes.

When `MessageHandlingExtension` is installed, the bundle also registers a mediator around advice that runs every compiled handler through `TransactionalUnitOfWorkRunner`. Read-only handlers are cheap because no transaction is opened unless a unit of work reports pending changes.
