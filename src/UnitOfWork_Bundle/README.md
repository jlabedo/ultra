# Unit Of Work Bundle

Symfony bundle wiring for Ultra unit-of-work services.

## Message Handling Integration

When `UnitOfWorkBundle` and `MessageHandlingBundle` are both installed, the bundle registers a handler aspect that manages a root unit of work for synchronous mediator handlers.

- Mediator handlers without an active unit of work get one in `MessageContext`, then flush and clear it after successful handling.
- The root flush runs through `TransactionManager`; the bundle registers `NoTransactionManager` by default when no real transaction manager is configured.
- Nested mediator sends and event bus publishes inherit the active unit of work through the propagated context.
- Synchronous event handlers join an existing unit of work when one is present, but direct event bus publishing does not create or flush a root unit of work.
- Async enqueue-time dispatch is left to `AsyncQueueUnitOfWork`; async worker execution should get its own lifecycle policy.
