# Symfony application assembly

Start with [application installation](development.md#installing-in-an-application). The [getting-started application](../examples/getting-started/README.md) is a minimal FrameworkBundle console application; it supplies its own DBAL connection without DoctrineBundle. It can be copied into a new directory using the repository replacement described in its setup instructions.

## Select the extensions

An installed component and an enabled container extension are separate choices. `UltraBundle` accepts an explicit list of `ContainerExtension` instances. The starter's [Kernel](../examples/getting-started/src/Kernel.php) enables just the components it uses:

```php
yield new FrameworkBundle();
yield new UltraBundle([
    new MapperExtension('GettingStartedGenerated'),
    new StateStoreExtension(),
    new UnitOfWorkExtension(),
]);
```

Use the imports and `registerBundles()` method in that file in a FrameworkBundle kernel. Its `MicroKernelTrait` loads `config/services.yaml` and `config/packages/`; its `getProjectDir()` identifies the application directory. The [console entry point](../examples/getting-started/bin/console) boots that kernel. Adapt the namespace, secret and environment selection to the application.

`new UltraBundle()` discovers all available default extensions. The current monorepo installs all components, so that choice enables more than StateStore. A selected extension can require adapters or services even though another component is the application's entry point. Supply each selected context's dependencies:

| Capability | Extensions | Application configuration |
| --- | --- | --- |
| Normalize values | `MapperExtension` | Discover normalizable resources; choose the generation namespace and build directory. See [Mapper setup](mapping/getting-started.md#symfony). |
| Route handlers and advice | `MessageHandlingExtension` | Autoconfigure handler/aspect services. For event publication, also use `EventsExtension` and discover event resources. See [message dispatch](message-handling/getting-started.md). |
| Persist relational states | `MapperExtension`, `StateStoreExtension`, `UnitOfWorkExtension` | State resources, mapped adapter factory, real transaction manager and application-owned SQL tables. See [StateStore setup](stored-state/getting-started.md). |
| Load event-sourced decision state | `EventsExtension`, `MapperExtension`, `EventSourcedStateExtension`, `MessageHandlingExtension`, `StatefulMessageHandlingExtension`, `UnitOfWorkExtension` | EventStore service, state/event discovery and a transaction manager matching durable storage. See [decision bindings](message-handling/decisions.md). |
| Project events | `MapperExtension`, `ProjectionExtension` | EventStore, projection services and `ProjectionImplementationInterface`. See [projection setup](projections/getting-started.md#symfony). |

Namespaces and dependencies can be found through the [context routing table](README.md#task-routing); this table groups capabilities rather than prescribing one bundle list for every application.

## Register services and resources

Enable autowiring and autoconfiguration on application services. Value and state classes are resources for compilation, not services with runtime constructor dependencies. In the starter's [service configuration](../examples/getting-started/config/services.yaml), `Contact` has autoconfiguration enabled and autowiring disabled. The `#[State]` attribute lets Ultra discover its mapping and precompile its normalizer.

The application provides `MappedRowSetAdapterFactory` and `TransactionManager` using the same DBAL connection. If using DoctrineBundle, substitute its connection service instead of the starter's [connection factory](../examples/getting-started/src/Database.php). Query guards additionally need the adapter described in [StateStore Symfony integration](stored-state/symfony.md).

Read the owning context for defaults. In particular, the unit-of-work extension defaults to `NoTransactionManager`; configure a real transaction manager before building that extension for durable writes. The starter does this through application service definitions. See [transaction wiring](transactions/symfony.md).

## Build and deploy

From the starter directory, `php bin/console cache:warmup` compiles the Symfony container and mapped state normalizers. Keep generated artifacts with the matching runtime code and namespace. Give independent configurations that coexist in a process distinct mapper generation namespaces; see the [Mapper compilation contract](mapping/README.md).

The standalone message and async recipes use `ContainerBuilder` directly and compile it when the script starts. A FrameworkBundle application should place the equivalent registrations in its kernel and service configuration so container compilation happens during its normal build. Keep queued routes compatible across deployments as required by the [async contract](message-handling/async.md#deployment-compatibility).
