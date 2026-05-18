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

The runner executes the callback, flushes the configured `UnitOfWork` through `TransactionManager`, and clears the unit of work afterward. The bundle registers `NoTransactionManager` by default when no real transaction manager is configured.
