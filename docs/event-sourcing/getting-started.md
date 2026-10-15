# Append and read a PostgreSQL event stream

Follow the [getting-started application setup](../../examples/getting-started/README.md#setup), including its table setup, then run from that application's directory:

```bash
php bin/events.php
```

Expected output is `Stored and loaded 1 event.` Each invocation uses a new course identifier and retains its event in PostgreSQL.

## Create storage and connect

The [setup script](../../examples/getting-started/bin/setup.php) executes `PostgresEventStoreQueryStreamsSchema('tutorial_es_')->createStatements()`. The generated DDL includes event storage, the relational domain-identifier index and lock helper functions. It requires a fresh installation for that prefix. Inspect and incorporate those statements into the application's migration workflow.

The [event script](../../examples/getting-started/bin/events.php) creates `PostgresEventStoreQueryStreams` with the [DBAL connection](../../examples/getting-started/src/Database.php) and the same prefix. This implementation avoids the `hstore` extension. Schema and runtime must use the same prefix and lock strategy; both use the shared/exclusive default here. For the alternative backend and strategies, read [PostgreSQL persistence](postgres.md).

## Guard an append and paginate

The script builds `StreamQuery::forStream(new DomainIdentifier('courseId', $courseId))->ofTypes('tutorial.course.named')` and loads the stream before deciding to append. It passes that result's `appendCondition()` into `append()`, along with an `Event` carrying the type name, scalar payload and domain identifier map.

The append owns its transaction in this direct-use recipe. A conflicting append raises `Ultra\EventStore\Exception\ConcurrencyException`; choose whether to surface it or load fresh state and decide again. See the [append contract](event-store.md).

Reads continue while `hasMore` is true, passing the returned checkpoint as `from`. The script uses a batch size of one to show the cursor path, and verifies that the new stream contains exactly one event. Follow [checkpoint semantics](event-store.md#checkpoint-semantics), rather than treating a sequence number as a complete checkpoint.

## Add Symfony or buffered application writes

Register `Ultra\EventStore\EventStore` as `PostgresEventStoreQueryStreams` with the application's connection and chosen prefix. Enable `UnitOfWorkExtension` to decorate that service with `EventStoreUnitOfWork` when application writes must be buffered together. Configure the real transaction manager on the same connection and dispatch work within `UnitOfWorkRunner`. The [transaction wiring](../transactions/symfony.md) owns these rules.

For event-sourced decision objects, continue with [state reconstruction](states.md) and [decision bindings](../message-handling/decisions.md). For a read model, follow the [projection recipe](../projections/getting-started.md); direct appends without its activity recorder do not notify the coordinator.
