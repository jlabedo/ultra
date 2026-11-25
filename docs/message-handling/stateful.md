# Stateful message handling

Read this contract when changing decision effects, state injection or CHECK. See [bindings and examples](decisions.md) for configuration.

## Two persistence approaches

Decision logic can live on a self-handling message marked with #[SelfHandler] or
on a separate handler marked with #[Handles]. Both organizations are supported;
#[DecisionHandler] identifies decision behavior independently of that routing choice.

Event-sourced state and stored state are peer approaches. Neither requires a state to represent a complete domain entity or an aggregate root. See the [glossary](../../GLOSSARY.md) and the [architectural rationale](../adr/0001-decision-specific-states.md).

Event-sourced decisions reconstruct their states from relevant events and append recognized returned events. Stored-state decisions load current values and prepare saves for recognized returned states. An event-sourced state is hydrated even when no events match; a missing stored state is represented by null when the parameter allows it, or causes a StateNotFoundException otherwise.

## Consistency responsibilities

For `#[DecisionHandler]`, the signature identifies injected states whose reads receive automatic optimistic concurrency protection. Event-sourced decisions retain append conditions from all loading rounds. Stored-state decisions register expectations for loaded or missing states, including states that are not subsequently saved.

`#[StateReader]` resolves bindings and loads stored states without registering the decision handler's optimistic expectations. Enroll those dependencies explicitly when a reader participates in a decision.

This is only part of a decision's consistency boundary. The caller is responsible for additional dependencies, including reads performed by services and queries that need explicit expectations or query guards. See [StateStore](../stored-state/README.md) for those mechanisms.

## Experimental CHECK mode

CHECK is an early proof of concept whose long-term usefulness and contract remain unresolved. Its current design should not be treated as a settled validation API.

The decision aspect loads states and executes the handler and its services. CHECK returns violations from an InvariantViolation, or an empty list on success, and bypasses the saving, appending and publication of returned effects. Other exceptions propagate. Stored-state expectations are registered before CHECK runs. Effects performed directly by the handler or its services are not prevented by this mode.

## Event publication and commit

A decision can return one object or an array containing both mapped states and registered events.
A stored-state decision can express a change of company plan in one return value.

```php
return [
    $company->withMonthlyMinutes($this->monthlyMinutes),
    new CompanyPlanChanged($this->companyId, $this->monthlyMinutes),
];
```

With a stored-state binding, the aspect buffers all returned mapped states. With an event-sourced
binding, it also appends the registered events as a batch under the decision's append conditions.
It then publishes each registered event once, in return order, to the configured event bus.
A mixed decision follows the same sequence. The stored event metadata and bus delivery share
the child context created for that event.

Stored-state publication requires the event registry (provided by `ultra/events`) and the event bus.
Only decisions with an event-sourced binding append events. Synchronous subscribers can read
the buffered changes in the same unit of work before its flush and commit.

For handlers producing external effects, follow the [asynchronous delivery and outbox contract](async.md#outbox-and-external-effects).

## Mixed decisions

Handlers can involve both event-sourced states and stored states. This capability still has limitations: derived state identifiers can depend only on states of the same persistence family, because each persistence family loads its states independently. Broader guarantees for mixed decisions are a desired future direction, rather than an established current contract.

## Flush, conflicts and retries

For flush ordering, transaction boundaries and nested work, use the [transaction contract](../transactions/README.md).

A concurrency conflict is distinct from a business invariant violation. Retry policy currently belongs to the caller; a framework retry module is planned in [TODO](../../TODO.md).

## Code references

- [DecisionAspect](../../src/MessageHandling/Stateful/DecisionAspect.php)
- [EventSourcedDecisionStates](../../src/MessageHandling/Stateful/EventSourcedDecisionStates.php)
- [StoredDecisionStates](../../src/MessageHandling/Stateful/StoredDecisionStates.php)
- [StateStoreReadAspect](../../src/MessageHandling/Stateful/StateStoreReadAspect.php)
- [DecisionMode](../../src/MessageHandling/Stateful/DecisionMode.php)
- [EventStoreUnitOfWork](../../src/EventSourcing/EventStore/EventStoreUnitOfWork.php)
- [AsyncQueueUnitOfWork](../../src/MessageHandling/Runtime/Async/AsyncQueueUnitOfWork.php)
- [PostgresAsyncQueue](../../src/MessageHandling/PostgresQueue/PostgresAsyncQueue.php)

## Test ownership and maintenance

The guarantee suites live in `src/MessageHandling/Tests/Stateful`; persistence primitives remain owned by
[Event sourcing](../event-sourcing/README.md#test-ownership) and
[StateStore](../stored-state/README.md#test-ownership-and-maintenance).

| Guarantee | Suite |
| --- | --- |
| Self and separate handlers receive the requested state, including nested and composite identities, multi-tag event-sourced states and nullable stored states and read-only handlers; independent compilations retain their own services and states after generated-file cleanup | [StateInjectionTest](../../src/MessageHandling/Tests/Stateful/StateInjectionTest.php) |
| Independent and dependent states load in the right rounds, observe prior decisions and propagate missing source states | [DependentStateLoadingTest](../../src/MessageHandling/Tests/Stateful/DependentStateLoadingTest.php) |
| Ambiguous, missing, cyclic or incompatible bindings fail during compilation | [BindingDeclarationTest](../../src/MessageHandling/Tests/Stateful/BindingDeclarationTest.php) |
| Loaded dependencies guard append or flush, even when they produce no returned save and during CHECK | [ReadConsistencyTest](../../src/MessageHandling/Tests/Stateful/ReadConsistencyTest.php) |
| CHECK executes the decision and services, preserves violations, propagates other exceptions and bypasses returned effects | [CheckModeTest](../../src/MessageHandling/Tests/Stateful/CheckModeTest.php) |
| Only registered returned objects are appended or saved; the original result is preserved, including mixed decisions | [DecisionEffectsTest](../../src/MessageHandling/Tests/Stateful/DecisionEffectsTest.php) |
| Stored and mixed decisions publish each event once after preparing states and any append, using the child context recorded in event metadata | [EventPublicationTest](../../src/MessageHandling/Tests/Stateful/EventPublicationTest.php) |
| Dumped Symfony containers assemble each available persistence family and select returned effects from the handler’s state parameters | [ContainerAssemblyTest](../../src/MessageHandling/Tests/Stateful/ContainerAssemblyTest.php) |

Providers express alternative persistence families, binding forms or result shapes. Shared support assembles
real extensions and cleans generated files; assertions remain in the suites. Cases run in PHPUnit's main
process. Each compilation owns a directory and a logical Mapper generation namespace; dumped containers
also have distinct class names. Every application receives fresh stores and services; applications with
events also receive a fresh test event bus.
The coexistence cases compile the same fixtures twice, remove the first application's generated files and
verify that both applications remain usable with independent state.

The test bus is injected through `EventBusInterface`, including as a synthetic service in dumped containers.
Publication ordering and context propagation still use the instrumented bus in `EventPublicationTest`;
the routing guarantees belong to [MessageHandling](README.md#guarantee-suites).

Use the shared [test-refactoring rules](../development.md#refactoring-tests) to maintain these guarantees
and compare coverage and mutations.
