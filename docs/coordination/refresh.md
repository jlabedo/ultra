# Refreshing materialized queries

`RefreshWorker` prepares work from current source state and publishes a replacement for a
bounded part of a materialized result. The application owns the query, the marker keys and
the rows each key affects. For an event-sourced example, see [compliance](../../examples/compliance/README.md).

## Declare and record effects

The producer identifies the affected keys and calls `DirtyKeyTracker::bump()` in the
source write transaction. A producer that moves data between scopes marks both the old and
new scopes. A coarse key can defer expanding a large cross-product until the refresh. SQL
and StateStore writers can participate by recording the same markers transactionally. The
worker does not derive dependencies from SQL or subscribe to source changes.

Use one publication channel for all keys whose results can overlap. Coarse and fine keys
can share a channel. Compliance uses one per account because its subject and qualification
refreshes overlap within an account. Channels whose results are independent can publish concurrently.

## Prepare and publish a step

`RefreshWorker::run()` reads the channel revision before observing a pending key. The
application prepares a `RefreshStep` using the observed key and counter and reads current
source state outside the publication transaction. Preparation must be read-only. The step
contains a write closure and a count of the observed invalidations it covers. An empty
result may still require deleting rows from the materialized result.

`PostgresRefreshPublication` owns a `READ COMMITTED` write transaction. It compares and
advances the revision before running the closure. This prevents another preparation based
on the old revision from publishing, even across processes. The closure and the worker's
`drainSeen()` run on the same connection and commit with the revision. A source write that
bumps a counter after observation can leave the published result briefly stale. Its increment
remains pending for the next pass. A failed write or acknowledgement rolls back publication.

A multi-step refresh can publish partial changes and progress with acknowledgement zero.
Use durable progress to resume after a restart. At completion, acknowledge only the
observed count covered by the whole scan. For example, retain the count from the first page
so changes arriving between pages remain pending. Progress and readiness written by the
application belong to the same transaction as the corresponding result rows.

The worker processes up to the supplied number of steps and stops on empty work or a
publication conflict. Its return value counts committed steps. A poller should keep polling
or inspect pending keys to distinguish a conflict from a drained queue. The worker neither
schedules activation nor guarantees a fresh answer to a reader. The application decides when
results are readable and whether to fall back to the source query.

Declare the revision table in [UltraBundle's migration configuration](README.md#postgresql-migrations)
or use `PostgresRefreshPublicationSchema` to install it directly. The tracker, publication
adapter and result writes must share a database connection. Keep revisions while workers run,
and route all overlapping result writes through the same publication channel. Resetting
materialized rows or progress outside that protocol is an offline operation.
