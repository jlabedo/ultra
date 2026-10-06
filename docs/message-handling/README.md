# Message handling

MessageHandling and MessageHandling_Compiler provide compiled routing and handler wrappers. MediatorInterface::send performs single dispatch to a matching handler and returns its result. EventBusInterface::publish performs multiple dispatch to matching event handlers with configured priorities.

Decision logic can be on the message itself or a separate handler. The stateful layer adds loading and optimistic protection for declared state parameters.

- [Stateful concepts and responsibilities](stateful.md)
- [Decision handler configuration and examples](decisions.md)
- [Asynchronous delivery](async.md)
- [Transactions and units of work](../transactions/README.md)

MessageContext is immutable. Propagated entries contain scalars, null or lists of those values; local entries prefixed with a dot can contain in-process values and are excluded by propagated(). MessageContextPropagator assigns message and correlation IDs; child contexts receive a new message ID, retain correlation and record the parent's message ID as causation.

See [MediatorInterface](../../src/MessageHandling/MediatorInterface.php), [EventBusInterface](../../src/MessageHandling/EventBusInterface.php) and [MessageContext](../../src/MessageHandling/MessageContext.php).

## Guarantee suites

The context's suites live in [MessageHandling/Tests](../../src/MessageHandling/Tests), including compiled routing and Postgres queue integration. Compiler fixtures and container support remain under [MessageHandling_Compiler/Tests](../../src/MessageHandling_Compiler/Tests) for consumers of that setup.

| Guarantee | Suite |
| --- | --- |
| Single dispatch, self handlers, event subscriptions and priority | [RoutingTest](../../src/MessageHandling/Tests/RoutingTest.php) |
| Service injection and dependency slots supplied by advice | [HandlerDependenciesTest](../../src/MessageHandling/Tests/HandlerDependenciesTest.php) |
| Advice receives success/failure and cleanup runs on either path | [AdviceExecutionTest](../../src/MessageHandling/Tests/AdviceExecutionTest.php) |
| Advice layers, priority and deterministic ties | [AdviceOrderingTest](../../src/MessageHandling/Tests/AdviceOrderingTest.php) |
| Immutable derived contexts and advice patches across success/failure | [AdviceContextTest](../../src/MessageHandling/Tests/AdviceContextTest.php) |
| Advice signatures, sender channels and independent compiled routers | [CompilationContractTest](../../src/MessageHandling/Tests/CompilationContractTest.php) |
| Propagated values and local in-process values | [ContextValuesTest](../../src/MessageHandling/Tests/ContextValuesTest.php) |
| IDs, correlation, causation and context through nested and queued dispatch | [ContextPropagationTest](../../src/MessageHandling/Tests/ContextPropagationTest.php) |

Async guarantees have their own [suite map](async.md#guarantee-suites). Follow the shared [test refactoring workflow](../development.md#refactoring-tests).
