# Ultra

Ultra is a private PHP monorepo for exploring composable application building blocks. Message handling is the primary application-facing API, while many packages can be used independently. Runtime performance guides the design; Symfony is the reference integration.

## Start here

- [Documentation by context and task](docs/README.md): contracts, usage and package ownership.
- [Contributor instructions](AGENTS.md): change rules and verification requirements.
- [IAM example](examples/iam/README.md): tenant-scoped RBAC with StateStore writes, direct SQL authorization reads and transactional auditing.

Runtime requirements and tooling are defined in [composer.json](composer.json).

## Status and license

Ultra is a research and implementation space, not a published framework.

Ultra is private and proprietary. No license for public use, distribution, or modification is currently granted.
