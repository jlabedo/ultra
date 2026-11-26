# Asynchronous delivery

An asynchronous handler is queued instead of executed inline. AsyncHandlerProxy records the original handler route in the envelope; QueueWorker resolves that route directly rather than sending the message through the mediator again.

The worker reserves a delivery, executes the handler and acknowledges it. Routing, handler lookup, execution
and acknowledgement failures are reported to the queue and propagate to the caller. `drain()` stops at the
first failure. Continue polling after reporting the failure to process ready messages, including retries.
Postgres reservations use leases and `SKIP LOCKED`. Workers can renew a lease through the receiver while
processing long-running work.

## Retries and dead letters

`PostgresAsyncQueue` and `InMemoryQueue` use an [`AsyncRetryPolicy`](../../src/MessageHandling/Runtime/Async/AsyncRetryPolicy.php).
The default allows five delivery attempts, including the first reservation, with immediate retries. Pass a
policy to the queue constructor to choose the total number of attempts and a fixed delay after a reported
failure. Share the same policy between workers consuming a given queue. A manual `release()` can specify
its own delay.

```php
$queue = new PostgresAsyncQueue(
    $connection,
    $codec,
    queueName: 'notifications',
    retryPolicy: new AsyncRetryPolicy(maxAttempts: 3, retryDelay: Duration::seconds(10)),
);
```

Each reservation consumes an attempt. A release at the limit moves the message to the dead-letter queue.
Postgres also applies the policy when decoding the reserved envelope fails. When a worker abandons its
final lease, the next reservation poll that encounters the expired lease quarantines the message. Only the
current lease token can acknowledge, release or renew a reserved message.

Postgres persists dead letters in the same table, scoped by queue name. They are excluded from delivery
and replacement by schedule key. `InMemoryQueue` provides the same operations for in-process use.
Use the [`DeadLetterQueue`](../../src/MessageHandling/Runtime/Async/DeadLetterQueue.php) API on the queue instance:

```php
$count = $queue->countFailed();
$page = $queue->listFailed(limit: 25, offset: 0);
$letter = $queue->inspectFailed(new AsyncMessageId($messageId));
$replayed = $queue->replayFailed(new AsyncMessageId($messageId));
```

Lists are ordered by failure time and message ID. Each dead letter carries the message ID, attempt count,
failure time, failure message and exception class when available. Postgres exposes the stored body,
headers and propagated attributes without calling the application decoder. In memory, inspection exposes
the original envelope. `inspectFailed()` returns `null` when the ID is absent from the queue's dead letters.

Replay atomically returns one dead letter to the original queue with its original ID and envelope. It is
available immediately with a fresh attempt budget and no schedule key. `replayFailed()` returns `true`
when it requeues the message and `false` if the ID is absent from the queue's dead letters.

## Outbox and external effects

AsyncQueueUnitOfWork buffers enqueues and AsyncReceiverUnitOfWork buffers acknowledgements. Compose them with application writes in the same unit of work and transaction connection. With PostgresAsyncQueue, enqueues commit atomically with those writes and become visible to workers after commit. Declaring a handler with `#[Asynchronous]` alone does not establish this configuration.
Both buffers consume completed operations after a successful flush, following the
[unit-of-work lifecycle](../transactions/README.md#core-contract).

An external action can succeed before queue acknowledgement fails. An outbox does not make that action atomic with acknowledgement. Use a stable idempotency key or reliable reconciliation when the external service supports it. Without that support, retrying an ambiguous outcome can duplicate the action, while avoiding retry can lose it.

## Deployment compatibility

To include the PostgreSQL queue table and its partial indexes in
[`bin/console ultra:schema:generate`](../schema/README.md#from-symfony), declare the schema in `UltraBundle`:

```yaml
ultra:
    async_queue:
        postgres:
            table_prefix: async_queue_
```

`table_prefix` defaults to `async_queue_`. Use the same unqualified prefix when constructing
`PostgresAsyncQueue`. The bundle setting controls migration generation. The application wires the
queue, its codec and its unit-of-work buffers.

Apply the generated migration before starting workers on an existing queue. The migration adds nullable
failure fields and updates indexes, preserving existing messages and their attempt counts.

Applications must handle duplicate delivery and preserve compatibility with handler routes and encoded
envelopes referenced by queued messages and dead letters during deployments.

See [QueueWorker](../../src/MessageHandling/Runtime/Async/QueueWorker.php) and [PostgresAsyncQueue](../../src/MessageHandling/PostgresQueue/PostgresAsyncQueue.php).

## Guarantee suites

These suites belong to [MessageHandling/Tests](../../src/MessageHandling/Tests), alongside the [routing and context guarantees](README.md#guarantee-suites).

| Guarantee | Suite |
| --- | --- |
| Deferred single dispatch, mixed event subscriptions, original routes and sender channels | [AsyncRoutingTest](../../src/MessageHandling/Tests/AsyncRoutingTest.php) |
| Dispatch versus execution advice for inline and queued handlers | [AdvicePhasesTest](../../src/MessageHandling/Tests/AdvicePhasesTest.php) |
| Release and exception propagation; drain stops on lookup, execution or acknowledgement failure | [DeliveryOutcomeTest](../../src/MessageHandling/Tests/DeliveryOutcomeTest.php) |
| Bounded retries, quarantine and replay | [DeadLetterQueueTest](../../src/MessageHandling/Tests/DeadLetterQueueTest.php) |
| Reservation ownership, expiry, renewal, stale deliveries and concurrent reservations | [QueueLeaseTest](../../src/MessageHandling/Tests/QueueLeaseTest.php) |
| Relative/absolute scheduling, release delay and invalid options | [QueueSchedulingTest](../../src/MessageHandling/Tests/QueueSchedulingTest.php) |
| Queue-name isolation for replacement, reservation and acknowledgement | [QueueIsolationTest](../../src/MessageHandling/Tests/QueueIsolationTest.php) |
| Replacing a pending message by key | [QueueReplacementTest](../../src/MessageHandling/Tests/QueueReplacementTest.php) |
| Persistent envelope bodies, headers and propagated attributes | [EnvelopeRepresentationTest](../../src/MessageHandling/Tests/EnvelopeRepresentationTest.php) |
| Buffered enqueues/acknowledgements, transaction visibility and assembly with the unit of work | [QueueTransactionTest](../../src/MessageHandling/Tests/QueueTransactionTest.php) |

Lease, scheduling and replacement cases run against memory and Postgres through providers. Persistence cases use independent connections to observe transaction visibility and competing reservations. Transaction ordering and EventStore behavior remain owned by their respective contexts; these suites verify queue participation in that composition.

Use the shared [test refactoring workflow](../development.md#refactoring-tests).
