# Asynchronous delivery

An asynchronous handler is queued instead of executed inline. AsyncHandlerProxy records the original handler route in the envelope; QueueWorker resolves that route directly rather than sending the message through the mediator again.

The worker reserves a delivery, executes the handler and acknowledges it. Failures release the delivery and propagate; drain stops at the first failure. Postgres reservations use leases and SKIP LOCKED. Expired deliveries can be reserved again. The current worker does not automatically renew leases, apply backoff or cap attempts.

## Outbox and external effects

AsyncQueueUnitOfWork buffers enqueues and AsyncReceiverUnitOfWork buffers acknowledgements. Compose them with application writes in the same unit of work and transaction connection. With PostgresAsyncQueue, enqueues commit atomically with those writes and become visible to workers after commit. Declaring a handler with `#[Asynchronous]` alone does not establish this configuration.

An external action can succeed before queue acknowledgement fails. An outbox does not make that action atomic with acknowledgement. Use a stable idempotency key or reliable reconciliation when the external service supports it. Without that support, retrying an ambiguous outcome can duplicate the action, while avoiding retry can lose it.

## Deployment compatibility

Applications must handle duplicate delivery and preserve compatibility with handler routes referenced by messages already in the queue during deployments. See [TODO](../../TODO.md) for planned retry work.

See [QueueWorker](../../src/MessageHandling/Async/QueueWorker.php) and [PostgresAsyncQueue](../../src/MessageHandling_PostgresQueue/PostgresAsyncQueue.php).
