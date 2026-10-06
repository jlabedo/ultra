# State Store Bundle

`StateStoreExtension` wires the state store into Symfony's dependency injection container. The default
`Ultra\SymfonyBundle\UltraBundle` includes this extension when the package is installed. A custom container can
call `StateStoreExtension::build()` before compilation, alongside the mapper and unit-of-work extensions.

## Services and state discovery

Register `#[Ultra\StateStore\Attribute\State]` classes as autoconfigured resources and repositories as autowired
services. The extension discovers state mappings, creates shared `MappedRowSet` services for their physical
tables, and registers `StateMetadataRegistry`, `StateTableRegistry` and `StateStore`. `StateTableRegistry` is tagged
for the composite unit of work. All registered roots and collections targeting one physical table must use the
same complete key columns; compilation rejects conflicting mappings. Collection keys include both owner and
item columns. A separate root view over an item table must therefore map that complete key.

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

    Ultra\StateStore\GuardLockAdapter:
        class: Ultra\StateStore\PostgresGuardLockAdapter
        arguments:
            $connection: '@doctrine.dbal.default_connection'
```

The transaction manager used by `TransactionalUnitOfWorkRunner`, mapped adapters, guard adapter and query
callbacks must all use the same connection. For in-memory use, register `InMemoryMappedRowSetAdapterFactory` and
`InMemoryGuardLockAdapter` with the same `InMemoryRowDatabase`.

See the [state mapping contract](mapping.md) for mappings and the [coworking service
configuration](../../examples/coworking/config/services.yaml) for a complete application example.

## Query guard declarations

`#[Ultra\StateStore\Attribute\DefineQueryGuard]` belongs on a constructor parameter typed `QueryGuard` in an
autowired service. Declarations on services with autowiring disabled are not discovered. Abstract services are
skipped. Collection item classes are not registered root states and cannot be watched independently.

```php
use Ultra\StateStore\Attribute\DefineQueryGuard;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\QueryGuardDefinition;

public function __construct(
    #[DefineQueryGuard('company_usage', static function (QueryGuardDefinition $definition): void {
        $definition
            ->watch(Booking::class, scopeBy: 'companyId')
            ->whenPropertiesChange('charged', 'start', 'end');
    })]
    private QueryGuard $companyUsage,
) {}
```

The configuration closure runs during compilation, after state metadata is available. Use it to declare mapped
dependencies, without reading application data or requiring runtime services. The compiler validates property paths and collections,
registers the writer rules in `StateTableRegistry` and injects the guard. A dumped container retains plain metadata
and ordinary guard services; it does not replay the configuration closures at runtime.

Compilation fails with `LogicException` for an empty name, a parameter with another type, an empty definition,
an unregistered watched state, an unknown mapped property or collection, a collection scope outside the owner identifier, conflicting definitions for the same name, or a
missing `GuardLockAdapter`. `whenPropertiesChange()` with no arguments raises `InvalidArgumentException`.
Repeated names must produce identical compiled scopes; dependency order and duplicates are normalized. Prefer one consistent declaration per query dependency.

The developer supplies complete query dependencies; the compiler does not inspect SQL. Read the [query guard
contract](query-guards.md) for decision ordering, replay, exceptions and the supported scope.
