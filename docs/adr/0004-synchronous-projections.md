# Synchronous projections and reconstruction

## Decision

Projection declarations select one of two modes through `ProjectionMode`:

- `Asynchronous` (default): workers apply persisted events and advance segment checkpoints.
- `Synchronous`: workers first reconstruct history; an explicit atomic activation then makes subsequent appends update the projection in their own transaction.

`ProjectionMode` is fixed for each projection identifier and read model. This lets asynchronous execution use segment storage without reading or locking the managed registry. Deployments introduce a synchronous replacement under a new identifier with its own read model, as described in the [projection contract](../projections/README.md#execution-modes).

`partitionBy` is required for asynchronous processing and managed reconstruction. Synchronous execution inherits event-store concurrency guarantees; partition dimensions do not introduce additional ordering or serialization. Concurrent effects must be safe for the projection's own storage model. A replay may choose a different order between independent events.

## Single append integration

`ProjectingEventStore` wraps the persistent event store. With buffering, `EventStoreUnitOfWork` wraps `ProjectingEventStore`, so immediate and buffered appends reach the same integration point. Application unit-of-work runners remain generic.

The append integration routes synchronous event types before opening a transaction. With no synchronous match or activity to record, the event store manages its ordinary append transaction. For projection work the append integration opens or joins the shared transaction. It obtains activation barriers and reads projection state for matching synchronous projections, persists events under the original append conditions, applies live synchronous projections, flushes their private persistence participants, and records asynchronous activity. It commits only when it owns the transaction. All durable effects use the same connection. A projection failure must abort the entire write transaction.

Handler execution follows append validation and stays under the activation barrier. Running handlers before the transaction could shorten it, but would require validating that the projection is still live in the same generation and that its prepared writes still satisfy any assumptions made by the handlers.

`ProjectionRuntime::applyBatch()` provides common batch application, the optional projection flush hook, and flushing of projection-owned units of work. Projections may instead write directly through the shared transactional connection. Projection persistence participants must not also be registered in the parent composite unit of work; they are exclusively owned and cleared by projection execution. There is no global prepare phase, stabilization loop, specialized application runner or dependency on parent participant order. Projection handlers do not append new domain events.

The application can supply a named MappedTable coordinator as an ordinary persistence participant. Its repositories receive dedicated buffers through constructor injection. This keeps storage selection in the persistence assembly and lets append, replay and reset share the same projection services. Each runtime uses its participants for one operation at a time.

## Reconstruction and activation

Managed synchronous projections persist a generation and phase (`building` or `live`). Checkpoints are used while building, never as a synchronous concurrency lock. Initializers and resetters participate in transactional lifecycle operations.

Writers and worker application transactions hold a shared transaction-scoped barrier per managed projection. Activation holds the exclusive barrier, waits for in-flight transactions, rereads segment checkpoints, applies the remaining history, and persists `live` atomically with the final projection writes. Workers acquire their shared barrier before claiming or loading managed batches and validate the current definition and phase under it. Managed workers replay only while the projection is building. A reset waits for in-flight managed batches to finish before starting a new generation. Shared barriers allow concurrent writers.

Catch-up uses the event store's gap-aware checkpoints and persistent reads, never the pending-event overlay or a naive maximum sequence. Activity and queue messages remain scheduling hints. Reconstruction must be explicitly started for a new projection even when there are no new appends.

Only appends handling a locally declared synchronous projection acquire the shared registration barrier. First registration can proceed alongside unrelated appends. Checking every registered definition on each append required a registry scan even for unrelated events. Writers now check only matching synchronous definitions as part of their phase lookup. Deployment owns compatibility across writers as described in the [projection contract](../projections/README.md#storage-and-deployment). Online replacement while serving an older read-model generation is outside this first design.

## Verification and implementation tracking

The authoritative current behavior belongs in [the projection contract](../projections/README.md). This ADR records the target design, not a claim that every item below is already implemented.

- [x] Declaration modes and validation.
- [x] Shared batch execution and private persistence participants.
- [x] Single transactional append integration, direct and buffered.
- [x] Managed activation state and transaction-scoped barriers, memory and PostgreSQL adapters.
- [x] Managed worker validation and explicit reconstruction/activation.
- [x] Symfony assembly and operator entry points.
- [x] Functional coverage of modes, rollback, buffering, resume and concurrency.
- [x] Updated current contracts and deployment constraints.
