# Synchronous projections and reconstruction

## Decision

Projection declarations select one of three modes through `ProjectionMode`:

- `Asynchronous` (default): workers apply persisted events and advance segment checkpoints.
- `Synchronous`: workers first reconstruct history; an explicit atomic activation then makes subsequent appends update the projection in their own transaction.
- `SynchronousFromNow`: every new append updates the projection immediately, without persisted activation state, an activation barrier or automatic history replay.

`partitionBy` is required for asynchronous processing and managed reconstruction. It is optional for `SynchronousFromNow`. Synchronous execution inherits event-store concurrency guarantees; partition dimensions do not introduce additional ordering or serialization. Concurrent effects must be safe for the projection's own storage model. A replay may choose a different order between independent events.

## Single append integration

`ProjectingEventStore` wraps the persistent event store. With buffering, `EventStoreUnitOfWork` wraps `ProjectingEventStore`, so immediate and buffered appends reach the same integration point. Application unit-of-work runners remain generic.

The append integration opens or joins the shared transaction, obtains activation barriers, reads projection state, persists events under the original append conditions, applies live synchronous projections, flushes their private persistence participants, and records asynchronous activity. It commits only when it owns the transaction. All durable effects use the same connection. A projection failure must abort the entire write transaction.

`ProjectionBatchExecutor` provides common batch application, the optional projection flush hook, and flushing of projection-owned units of work. Projections may instead write directly through the shared transactional connection. Projection persistence participants must not also be registered in the parent composite unit of work; they are exclusively owned and cleared by projection execution. There is no global prepare phase, stabilization loop, specialized application runner or dependency on parent participant order. Projection handlers do not append new domain events.

## Reconstruction and activation

Managed synchronous projections persist a generation and phase (`building` or `live`). Checkpoints are used while building, never as a synchronous concurrency lock. Initializers and resetters participate in transactional lifecycle operations.

Writers and worker application transactions hold a shared transaction-scoped barrier per managed projection. Activation holds the exclusive barrier, waits for in-flight transactions, rereads segment checkpoints, applies the remaining history, and persists `live` atomically with the final projection writes. Workers acquire their shared barrier before claiming/loading managed batches and validate current state under it; no managed batch is prefetched outside that transaction. Generation reset is excluded until the batch transaction finishes. Obsolete work must not write after activation or reset. Barriers do not serialize ordinary writers against one another.

Catch-up uses the event store's gap-aware checkpoints and persistent reads, never the pending-event overlay or a naive maximum sequence. Activity and queue messages remain scheduling hints. Reconstruction must be explicitly started for a new projection even when there are no new appends.

All appends share a registration barrier; first registration holds its exclusive counterpart and waits for old writes to finish. Structural registry checks reject missing or incompatible managed definitions, but cannot detect handler-body changes. All writers must know a managed projection before activation; incompatible deployments must not continue silently writing without it. Immediate activation has no global persisted start instant and requires coordinated writer rollout. It does not imply historical completeness. Online replacement while serving an older read-model generation is outside this first design.

## Verification and implementation tracking

The authoritative current behavior belongs in [the projection contract](../projections/README.md). This ADR records the target design, not a claim that every item below is already implemented.

- [x] Declaration modes and validation.
- [x] Shared batch execution and private persistence participants.
- [x] Single transactional append integration, direct and buffered.
- [x] Managed activation state and transaction-scoped barriers, memory and PostgreSQL adapters.
- [x] Worker fencing and explicit reconstruction/activation.
- [x] Symfony assembly and operator entry points.
- [x] Functional coverage of modes, rollback, buffering, resume and concurrency.
- [x] Updated current contracts and deployment constraints.
