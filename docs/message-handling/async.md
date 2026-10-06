# Asynchronous delivery

An asynchronous handler is queued instead of executed inline. AsyncHandlerProxy records the original handler route in the envelope; QueueWorker resolves that route directly rather than sending the message through the mediator again.

The worker reserves a delivery, executes the handler and acknowledges it. Failures release the delivery and propagate; drain stops at the first failure. Postgres reservations use leases and SKIP LOCKED. Expired deliveries can be reserved again. The current worker does not automatically renew leases, apply backoff or cap attempts.

## Outbox and external effects

AsyncQueueUnitOfWork buffers enqueues and AsyncReceiverUnitOfWork buffers acknowledgements. Compose them with application writes in the same unit of work and transaction connection. With PostgresAsyncQueue, enqueues commit atomically with those writes and become visible to workers after commit. Declaring a handler with `#[Asynchronous]` alone does not establish this configuration.

An external action can succeed before queue acknowledgement fails. An outbox does not make that action atomic with acknowledgement. Use a stable idempotency key or reliable reconciliation when the external service supports it. Without that support, retrying an ambiguous outcome can duplicate the action, while avoiding retry can lose it.

## Deployment compatibility

Applications must handle duplicate delivery and preserve compatibility with handler routes referenced by messages already in the queue during deployments. See [TODO](../../TODO.md) for planned retry work.

See [QueueWorker](../../src/MessageHandling/Async/QueueWorker.php) and [PostgresAsyncQueue](../../src/MessageHandling_PostgresQueue/PostgresAsyncQueue.php).

## Guarantee suites

These suites belong to [MessageHandling/Tests](../../src/MessageHandling/Tests), alongside the [routing and context guarantees](README.md#guarantee-suites).

| Guarantee | Suite |
| --- | --- |
| Deferred single dispatch, mixed event subscriptions, original routes and sender channels | [AsyncRoutingTest](../../src/MessageHandling/Tests/AsyncRoutingTest.php) |
| Dispatch versus execution advice for inline and queued handlers | [AdvicePhasesTest](../../src/MessageHandling/Tests/AdvicePhasesTest.php) |
| Release and exception propagation; drain stops on lookup, execution or acknowledgement failure | [DeliveryOutcomeTest](../../src/MessageHandling/Tests/DeliveryOutcomeTest.php) |
| Reservation ownership, expiry, renewal, stale deliveries and concurrent reservations | [QueueLeaseTest](../../src/MessageHandling/Tests/QueueLeaseTest.php) |
| Relative/absolute scheduling, release delay and invalid options | [QueueSchedulingTest](../../src/MessageHandling/Tests/QueueSchedulingTest.php) |
| Queue-name isolation for replacement, reservation and acknowledgement | [QueueIsolationTest](../../src/MessageHandling/Tests/QueueIsolationTest.php) |
| Replacing a pending message by key | [QueueReplacementTest](../../src/MessageHandling/Tests/QueueReplacementTest.php) |
| Persistent envelope bodies, headers and propagated attributes | [EnvelopeRepresentationTest](../../src/MessageHandling/Tests/EnvelopeRepresentationTest.php) |
| Buffered enqueues/acknowledgements, transaction visibility and assembly with the unit of work | [QueueTransactionTest](../../src/MessageHandling/Tests/QueueTransactionTest.php) |

Lease, scheduling and replacement cases run against memory and Postgres through providers. Persistence cases use independent connections to observe transaction visibility and competing reservations. Transaction ordering and EventStore behavior remain owned by their respective contexts; these suites verify queue participation in that composition.

Use the shared [test refactoring workflow](../development.md#refactoring-tests).
