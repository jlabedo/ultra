# Dispatch your first message

Install the [getting-started application](../../examples/getting-started/README.md#setup). This recipe needs no PostgreSQL connection or table setup. From that application's directory:

```bash
php bin/messages.php
```

Expected output:

```text
before
after
Hello, Ada!
```

## Declare a handler and advice

The complete [message script](../../examples/getting-started/bin/messages.php) defines `Greet` as a message value and `GreetingHandler::greet()` as its `#[Handles]` method. The first typed parameter identifies the message; the returned string becomes the result of `MediatorInterface::send()`.

`GreetingLog` demonstrates `#[Before]` and `#[After]` advice. Each method receives the message and `MessageContext`. Their default matcher applies to all discovered handlers. `Before` runs before the handler, and `After` runs on both success and failure. The example prints the returned greeting after the whole dispatch has completed, which explains the output order. Narrow matchers for application-specific advice; see the attributes' API and the [advice guarantees](README.md#guarantee-suites).

## Compile and dispatch

The script creates a Symfony `ContainerBuilder`, sets `kernel.build_dir`, builds `MessageHandlingExtension`, registers the handler and aspect with autoconfiguration enabled, and compiles. It then obtains `MediatorInterface`, sends `new Greet('Ada')` and checks the result.

In a FrameworkBundle application, enable the extension in the [kernel](../symfony.md#select-the-extensions), register handlers and aspects through ordinary autowired/autoconfigured application service discovery, and inject the mediator into an entry-point service. Message objects are constructed for each call; they are not handler services.

Use `EventBusInterface::publish()` for multiple subscribers: enable `EventsExtension`, register event classes as event resources (for example with `#[Ultra\Events\Attribute\Event]` and autoconfiguration), and annotate subscriber methods with `#[EventHandler]`. These are synchronous subscriptions unless marked asynchronous. Durable event persistence is a separate responsibility; see [event publication and commit](stateful.md#event-publication-and-commit).

For state-injected decisions continue with [stateful contracts](stateful.md) and [decision bindings](decisions.md). For deferred delivery use the [async setup recipe](async-setup.md); adding an attribute alone does not establish an outbox transaction.
