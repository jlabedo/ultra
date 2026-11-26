# Configure a durable queue and worker

Follow the [getting-started application setup](../../examples/getting-started/README.md#setup). From that application's directory, run the producer and consumer in separate processes:

```bash
php bin/async.php send
php bin/async.php consume
php bin/async.php consume
```

On an initially empty tutorial queue, expected output is:

```text
Contact and queued notification committed.
Processed 1 notification(s).
Processed 0 notification(s).
```

## Assemble persistent delivery

The [setup script](../../examples/getting-started/bin/setup.php) creates the queue using `PostgresAsyncQueueSchema('tutorial_queue_')` and the application-owned contact and notification tables.

The complete [async script](../../examples/getting-started/bin/async.php) supplies:

- One DBAL connection for mapped writes, queue storage and `DoctrineTransactionManager`.
- `MapperExtension`, `StateStoreExtension`, `UnitOfWorkExtension` and `MessageHandlingExtension`.
- A normalizable `Notify` message resource and autoconfigured `NotificationHandler` with `#[Handles]` and `#[Asynchronous]`.
- `MapperAsyncEnvelopeCodec` and `PostgresAsyncQueue`, with the same prefix as the schema and queue name `tutorial` in both processes.
- Aliases from `AsyncMessageSender` and `AsyncMessageReceiver` to that queue, supplied before compilation.

When UnitOfWork is configured, MessageHandling's compiler wraps those sender/receiver services in `AsyncQueueUnitOfWork` and `AsyncReceiverUnitOfWork` and adds them to the composite flush. It also supplies `QueueWorker` with the receiver, handler container and runner. The generated original handler routes remain available for the worker. These registrations are visible in the example; additional manual decorators are unnecessary for this composition.

## Commit work, then consume

The producer encloses a StateStore contact write and `mediator->send(new Notify(...))` in one `UnitOfWorkRunner::run()` boundary. The queued message and contact are flushed on the same connection and committed together. `send()` queues the async route; the notification handler executes later.

The consumer calls `QueueWorker::drain()`. Its handler observes and inserts a [notification state](../../examples/getting-started/src/Domain/Notification.php), keyed by the message's stable business ID. The receiver buffers acknowledgement into the same transaction as that state write. A second drain sees no remaining delivery. The example's database notification is a stored application result, not an email or other external effect.

## Run as a service and deploy

`drain()` is a finite batch, stopping when no delivery is available. For a persistent worker, repeatedly call `processOne()`, wait when it returns false, handle errors according to the application's retry policy, and support graceful shutdown in the supervising process. The current worker stops a drain on the first failure. Lease renewal, retry limits and backoff require application policy; see [delivery guarantees](async.md).

In Symfony, move the script's service definitions into application configuration and expose a Console command receiving `QueueWorker`. See [Symfony assembly](../symfony.md). Producer and worker need the same message classes, codec, queue name and compatible generated routes. The standalone example uses the same source and build directory for both processes; it recompiles at startup for demonstration.

Retain routes and message compatibility for deliveries queued before a deployment. Stable business IDs and duplicate-safe handlers remain necessary when delivery is repeated. External effects need their own idempotency or reconciliation policy, as described in [outbox and external effects](async.md#outbox-and-external-effects).
