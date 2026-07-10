# Terms of Use state-store example

This is the state-store counterpart to `examples/terms-of-use`: it exposes the
same commands, status query, and HTTP API without an event store or event
replay.

- The current terms live in one `current_terms_of_use` row.
- Each user acceptance lives in one `user_terms_of_use_acceptance` row.
- `TermsOfUse` is an embedded value object, flattened to relational columns by
  `#[StateEmbedded]`.
- `DefineTermsOfUse` and `AcceptCurrentTermsOfUse` receive domain states and
  return their next state. The `#[DecisionHandler]` aspect owns loading, saving,
  and optimistic expectations.

The acceptance keeps both the terms identifier and URL. A change to either
value makes `mustAcceptCurrentTermsOfUse()` return `true`. With no current terms,
the status is `false` because there is nothing to accept. The state-row key
`currentTermsOfUseStateId` (`current`) is technical; `TermsOfUse::$id` identifies
the document version accepted by a user.

## Run

```bash
composer install
composer test
symfony server:start --dir=public
```

The example configures `InMemoryMappedRowSetAdapterFactory`, so all state is
lost when the application stops. Substitute a persistent mapped-row adapter for
a production use case. Business handlers only express the domain transition.

## Framework note

This example exposed that `StatefulMessageHandling` was coupled to the event
store and event-sourced state packages even though its state-store decision
aspect does not use them. Those dependencies are now optional, allowing this
example to stay free of event-sourcing infrastructure.

`#[StateReader]` is the read-only counterpart to `#[DecisionHandler]`: it
injects states into a query without registering optimistic write expectations.

## HTTP API

```bash
curl -X PUT http://127.0.0.1:8000/terms-of-use/current \
  -H 'content-type: application/json' \
  -d '{"id":"2026-01","url":"https://example.test/terms/2026-01"}'

curl http://127.0.0.1:8000/users/ada/terms-of-use/status

curl -X POST http://127.0.0.1:8000/users/ada/terms-of-use/acceptance
```
