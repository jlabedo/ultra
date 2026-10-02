# Asynchronous delivery

An asynchronous handler is queued instead of executed inline. AsyncHandlerProxy records the original handler route in the envelope; QueueWorker resolves that route directly rather than sending the message through the mediator again.

The worker reserves a delivery, executes the handler and acknowledges it. Failures release the delivery and propagate; drain stops at the first failure. Postgres reservations use leases and SKIP LOCKED. Expired deliveries can be reserved again. The current worker does not automatically renew leases, apply backoff or cap attempts.

AsyncQueueUnitOfWork buffers enqueues and AsyncReceiverUnitOfWork buffers acknowledgements. Atomicity with application writes requires composition in the same unit of work and transaction connection. See [publication and external-effect responsibilities](stateful.md#event-publication-and-commit).

Applications must handle duplicate delivery and preserve compatibility with handler routes referenced by messages already in the queue during deployments. Stable route identities are a possible future evolution, not a current guarantee.

See [QueueWorker](../../src/MessageHandling/Async/QueueWorker.php) and [PostgresAsyncQueue](../../src/MessageHandling_PostgresQueue/PostgresAsyncQueue.php).
