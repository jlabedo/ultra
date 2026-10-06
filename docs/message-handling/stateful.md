# Stateful message handling

Read this contract when changing decision aspects, state injection or CHECK. See [bindings and examples](decisions.md) for configuration.

## Two persistence approaches

Decision logic can live on a self-handling message marked with #[SelfHandler] or
on a separate handler marked with #[Handles]. Both organizations are supported;
#[DecisionHandler] identifies decision behavior independently of that routing choice.

Event-sourced state and stored state are peer approaches. Neither requires a state to represent a complete domain entity or an aggregate root. See the [glossary](../../GLOSSARY.md) and the [architectural rationale](../adr/0001-decision-specific-states.md).

Event-sourced decisions reconstruct their states from relevant events and append recognized returned events. Stored-state decisions load current values and prepare saves for recognized returned states. An event-sourced state is hydrated even when no events match; a missing stored state is represented by null when the parameter allows it, or causes a StateNotFoundException otherwise.

## Consistency responsibilities

For `#[DecisionHandler]`, the signature identifies injected states whose reads receive automatic optimistic concurrency protection. Event-sourced decisions retain append conditions from all loading rounds. Stored-state decisions register expectations for loaded or missing states, including states that are not subsequently saved.

`#[StateReader]` resolves bindings and loads stored states without registering the decision aspects' optimistic expectations. Enroll those dependencies explicitly when a reader participates in a decision.

This is only part of a decision's consistency boundary. The caller is responsible for additional dependencies, including reads performed by services and queries that need explicit expectations or query guards. See [StateStore](../stored-state/README.md) for those mechanisms.

## Experimental CHECK mode

CHECK is an early proof of concept whose long-term usefulness and contract remain unresolved. Its current design should not be treated as a settled validation API.

The aspects still load states and execute the decision handler and its services. CHECK returns violations from an InvariantViolation, or an empty list on success, and bypasses the aspects' returned-event append/publication and returned-state saves. Other exceptions propagate. Stored-state expectations are registered before CHECK runs. Effects performed directly by the handler or its services are not prevented by this mode.

## Event publication and commit

The event-sourced decision aspect calls append before publishing returned events to the optional EventBus. With EventStoreUnitOfWork, append buffers the events, so publication can happen before the flush and database commit. Synchronous event handlers participate in the current processing and can prepare additional unit-of-work changes.

For handlers producing external effects, follow the [asynchronous delivery and outbox contract](async.md#outbox-and-external-effects).

## Mixed decisions

Handlers can involve both event-sourced states and stored states. This capability still has limitations: derived state identifiers can depend only on states of the same persistence family, because the two aspects load their states independently. Broader guarantees for mixed decisions are a desired future direction, rather than an established current contract.

## Flush, conflicts and retries

For flush ordering, transaction boundaries and nested work, use the [transaction contract](../transactions/README.md).

A concurrency conflict is distinct from a business invariant violation. Retry policy currently belongs to the caller; a framework retry module is planned in [TODO](../../TODO.md).

## Code references

- [EventSourcedDecisionAspect](../../src/StatefulMessageHandling/EventSourcedDecisionAspect.php)
- [StateStoreDecisionAspect](../../src/StatefulMessageHandling/StateStoreDecisionAspect.php)
- [StateStoreReadAspect](../../src/StatefulMessageHandling/StateStoreReadAspect.php)
- [DecisionMode](../../src/StatefulMessageHandling/DecisionMode.php)
- [EventStoreUnitOfWork](../../src/EventStore/EventStoreUnitOfWork.php)
- [AsyncQueueUnitOfWork](../../src/MessageHandling/Async/AsyncQueueUnitOfWork.php)
- [PostgresAsyncQueue](../../src/MessageHandling_PostgresQueue/PostgresAsyncQueue.php)

## Test ownership and maintenance

The guarantee suites live in `src/StatefulMessageHandling/Tests`; persistence primitives remain owned by
[Event sourcing](../event-sourcing/README.md#test-ownership) and
[StateStore](../stored-state/README.md#test-ownership-and-maintenance).

| Guarantee | Suite |
| --- | --- |
| Self and separate handlers receive the requested state, including nested and composite identities, nullable stored states and read-only handlers; independent compilations retain their own services and states after generated-file cleanup | [StateInjectionTest](../../src/StatefulMessageHandling/Tests/StateInjectionTest.php) |
| Independent and dependent states load in the right rounds, observe prior decisions and propagate missing source states | [DependentStateLoadingTest](../../src/StatefulMessageHandling/Tests/DependentStateLoadingTest.php) |
| Ambiguous, missing, cyclic or incompatible bindings fail during compilation | [BindingDeclarationTest](../../src/StatefulMessageHandling/Tests/BindingDeclarationTest.php) |
| Loaded dependencies guard append or flush, even when they produce no returned save and during CHECK | [ReadConsistencyTest](../../src/StatefulMessageHandling/Tests/ReadConsistencyTest.php) |
| CHECK executes the decision and services, preserves violations, propagates other exceptions and bypasses returned effects | [CheckModeTest](../../src/StatefulMessageHandling/Tests/CheckModeTest.php) |
| Only registered returned objects are appended or saved; the original result is preserved, including mixed decisions | [DecisionEffectsTest](../../src/StatefulMessageHandling/Tests/DecisionEffectsTest.php) |
| Events are appended before publication, with the same final child context in storage and on the bus | [EventPublicationTest](../../src/StatefulMessageHandling/Tests/EventPublicationTest.php) |
| Dumped Symfony containers assemble each available persistence family and select aspects from the handler’s state parameters | [ContainerAssemblyTest](../../src/StatefulMessageHandling/Tests/ContainerAssemblyTest.php) |

Providers express alternative persistence families, binding forms or result shapes. Shared support assembles
real extensions and cleans generated files; assertions remain in the suites. Cases run in PHPUnit's main
process. Each compilation owns a directory and a logical Mapper generation namespace; dumped containers
also have distinct class names. Every application receives fresh stores and services; applications with
events also receive a fresh test event bus.
The coexistence cases compile the same fixtures twice, remove the first application's generated files and
verify that both applications remain usable with independent state.

The test bus is injected through `EventBusInterface`, including as a synthetic service in dumped containers.
It avoids an existing limitation of the empty compiled event bus: its identical generated content has the
same class name in different directories, and loading both files would redeclare the class. Publication
ordering and context propagation still use the instrumented bus in `EventPublicationTest`.

Use the shared [test-refactoring rules](../development.md#refactoring-tests) to maintain these guarantees
and compare coverage and mutations.
