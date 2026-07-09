# Terms of Use example

This small Symfony application models a single current set of terms of use.

- `DefineTermsOfUse` makes an identifier/URL pair current.
- `AcceptCurrentTermsOfUse` records the exact pair currently in force for a user.
- `GetTermsOfUseAcceptanceStatus` answers whether that user must accept again.

The acceptance stores both the identifier and URL. Consequently, changing either
one requires a fresh acceptance. A user does not need to accept anything while
no current terms have been defined.

The write commands load the current-terms and user streams together. This gives
the acceptance command optimistic concurrency over both streams: it cannot
silently accept an outdated version if the terms change concurrently.

## Run

```bash
composer install
composer test
symfony server:start --dir=public
```

The example uses `InMemoryEventStore`; restarting the application clears its
data. Replace that service with a durable event-store implementation for a
production application.

## HTTP API

```bash
curl -X PUT http://127.0.0.1:8000/terms-of-use/current \
  -H 'content-type: application/json' \
  -d '{"id":"2026-01","url":"https://example.test/terms/2026-01"}'

curl http://127.0.0.1:8000/users/ada/terms-of-use/status

curl -X POST http://127.0.0.1:8000/users/ada/terms-of-use/acceptance
```
