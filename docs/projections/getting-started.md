# Build and rebuild a persisted projection

Follow the [getting-started application setup](../../examples/getting-started/README.md#setup). From that application's directory:

```bash
php bin/projections.php append
php bin/projections.php work
php bin/projections.php work
php bin/projections.php rebuild
```

With a fresh tutorial event store, the append reports zero read-model rows; the first worker projects one event and reports one row; the second worker projects zero events; rebuild replays one event and restores one row. If you already ran the [EventStore recipe](../event-sourcing/getting-started.md), its course events are included too.

## Define the read model

The complete [projection script](../../examples/getting-started/bin/projections.php) declares `CourseNamed` with event name `tutorial.course.named`, and `CourseNames` with projection name `tutorial.course_names` and partition dimension `courseId`. Its `#[ProjectionHandler]` upserts the read-model row on the provided connection. Its `#[ProjectionResetter]` clears the projection-owned table for offline rebuild.

The event's `domainIds` must include `courseId`: that identifier drives segment queries and bucket activity. Choose a partition dimension that covers the application's ordering dependencies; see [partition ordering](README.md#partition-ordering).

## Create and assemble storage

The [setup script](../../examples/getting-started/bin/setup.php) creates the event store and application-owned `tutorial_course_names` table, plus:

- `PostgresProjectionSegmentSchema('tutorial_projection_')` for checkpoints and segment claims.
- `PostgresDirtyKeyTrackerSchema('tutorial_projection_dirty_keys')` for bucket activity.
- `PostgresCoordinatorLeaseSchema('tutorial_projection_coordinator_lease')` for the coordinator lease.

These names match `PostgresProjectionImplementation($connection, 'tutorial_projection_')`. The script obtains the segment repository, dirty-key tracker, lease and projection transaction manager from that implementation. It composes `ProjectionRuntime`, `ProjectionCatalog`, `ProjectionActivity`, `ProjectionActivityRecorder`, `ProjectionActivityEventStore`, `ProjectionRunner` and `ProjectionCoordinator` explicitly, so no hidden test harness is needed.

All read-model writes and checkpoint updates use the same connection and projection transaction manager. Refer to the [projection contract](README.md) for failures and claims.

## Record activity and process it

`append` appends through `ProjectionActivityEventStore` inside a DBAL transaction. The event, dirty bucket and wake bookkeeping commit together. `work` constructs a coordinator and runs it against the persisted activity. The default scaling policy keeps processing on the leader, without activating follower workers.

The recipe uses `InMemoryProjectionActivator` to record signals in the producing process, and explicitly invokes the worker in another process. Signals themselves do not cross processes here; PostgreSQL activity survives and the worker polls it. Schedule or supervise repeated worker runs for continuous processing. Choose an external activator when signals should wake workers; durable checkpoints and activity retain their own responsibilities.

Appends made directly to the underlying event store bypass activity recording. Historical events can be included by a backfill or rebuild; simply having unread history does not create a new wake signal.

## Rebuild offline

Stop concurrent writers and projection workers for this recipe's offline rebuild. `replayFromBeginning()` deletes projection state/checkpoints through the resetter and streams history through the same transactional claim path, one event per batch here. It does not wait for future events and is not an online zero-downtime migration.

Keep source events intact. For large or continuously available read models, choose a separate version's storage and an application-managed backfill/cutover strategy rather than clearing the active read table.

## Symfony

Enable `MapperExtension` and `ProjectionExtension`, discover the projection service through autoconfiguration, and supply `EventStore` plus `ProjectionImplementationInterface` using `PostgresProjectionImplementation` and the application's connection/prefix. Register event types as normalizable resources for precompilation. Create the corresponding schema before running workers.

The [Symfony integration contract](README.md#symfony-integration) describes activator selection. If MessageHandling is enabled, the default activator sends coordinator/segment messages through the mediator; configure durable delivery as in the [async recipe](../message-handling/async-setup.md). For a polling leader without followers, explicitly supply `InMemoryProjectionActivator` and use the default scaling policy.

`php bin/console ultra:projection:run --worker-id=projection-1 --batch-size=100 -v` runs the extension's continuous coordinator command; supervise it and stop it through process management. This command belongs to an application enabling `ProjectionExtension`, not the starter's StateStore-only kernel. See [Symfony assembly](../symfony.md) to select extensions and supply their services.
