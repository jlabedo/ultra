# Message handling

MessageHandling and MessageHandling_Compiler provide compiled routing and handler wrappers. MediatorInterface::send performs single dispatch to a matching handler and returns its result. EventBusInterface::publish performs multiple dispatch to matching event handlers with configured priorities.

Decision logic can be on the message itself or a separate handler. The stateful layer adds loading and optimistic protection for declared state parameters.

- [Stateful concepts and responsibilities](stateful.md)
- [Decision handler configuration and examples](decisions.md)
- [Asynchronous delivery](async.md)
- [Transactions and units of work](../transactions/README.md)

MessageContext is immutable. Propagated entries contain scalars, null or lists of those values; local entries prefixed with a dot can contain in-process values and are excluded by propagated(). MessageContextPropagator assigns message and correlation IDs; child contexts receive a new message ID, retain correlation and record the parent's message ID as causation.

See [MediatorInterface](../../src/MessageHandling/MediatorInterface.php), [EventBusInterface](../../src/MessageHandling/EventBusInterface.php) and [MessageContext](../../src/MessageHandling/MessageContext.php).
