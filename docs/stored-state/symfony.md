# State Store Bundle

`StateStoreExtension` wires the state store into Symfony's dependency injection container. The default
`Ultra\SymfonyBundle\UltraBundle` includes this extension and `MappedTableExtension` when installed.
In a custom container, build both extensions before compilation, alongside the mapper and unit-of-work extensions.

## Services and state discovery

Register the independent [MappedTable definitions](mapped-table.md#defined-row-sets-and-owner-collections)
and `#[Ultra\StateStore\Attribute\State]` classes as autoconfigured resources. Register repositories as
autowired services. The compiler binds each state and collection to the definition for its physical table.
StateStore and services injecting the row set by definition share the same instance. The composite unit of
work enrolls a single `MappedTableUnitOfWork` for these row sets.

Compilation verifies the complete key, mapped columns, normalized PHP representations and nullability.
A state may map a subset of the non-key columns. An item table needs its complete owner and item key
and a declared owner dependency matching the parent state and column mapping. Declare collection items in
the property's Mapper type, for example `list<OrderLineState>`.

The application provides `MappedRowSetAdapterFactory` and a configured mapper. Query guards additionally require
`GuardLockAdapter`. For a PostgreSQL application using DoctrineBundle:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\:
        resource: '../src/'

    Ultra\MappedTable\MappedRowSetAdapterFactory:
        class: Ultra\MappedTable\PostgresMappedRowSetAdapterFactory
        arguments:
            $connection: '@doctrine.dbal.default_connection'

    Ultra\MappedTable\GuardLockAdapter:
        class: Ultra\MappedTable\PostgresGuardLockAdapter
        arguments:
            $connection: '@doctrine.dbal.default_connection'
```

The transaction manager used by `TransactionalUnitOfWorkRunner`, mapped adapters, guard adapter and query
callbacks must all use the same connection. For in-memory use, register `InMemoryMappedRowSetAdapterFactory` and
`InMemoryGuardLockAdapter` with the same `InMemoryRowDatabase`. Use that database as the
`TransactionManager` for an in-memory runner.

See the [state mapping contract](mapping.md) for mappings and the [coworking service
configuration](../../examples/coworking/config/services.yaml) for a complete application example.

## Query guard declarations

Register [MappedTable query guards](query-guards.md) against the shared row set definitions. The same guard
participates in direct writes and writes prepared by StateStore.
