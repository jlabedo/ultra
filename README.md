# Ultra

Ultra is a collection of composable PHP application building blocks. Each building block addresses a specific problem, such as dynamic consistency boundaries for event sourcing or generalized optimistic locking for stored state. Runtime performance guides the design; Symfony is the reference integration.

## Start here

- [Documentation by context and task](docs/README.md): contracts, usage and package ownership.
- [Installation from GitHub](docs/development.md#installing-in-an-application): use Ultra in another application with Composer.
- [Contributor instructions](AGENTS.md): change rules and verification requirements.
- [IAM example](examples/iam/README.md): tenant-scoped RBAC with StateStore writes, direct SQL authorization reads and transactional auditing.

Runtime requirements and tooling are defined in [composer.json](composer.json).

## Status and license

Ultra is experimental. APIs and behavior can change without backward compatibility guarantees. It is a personal project maintained as time permits; contributions are welcome, with acceptance based on their fit and maintenance cost.

Ultra's original source code, including its Git history, is available under the [MIT license](LICENSE). Third-party tools retained in earlier revisions keep their original licenses; see [third-party notices](THIRD_PARTY_NOTICES). Packagist publication and versioned releases are planned separately from opening the source repository.
