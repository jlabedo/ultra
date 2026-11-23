# MappedTable rewrite preparation in Ultra

Copy only the [portable brief directory](mapped-table-rewrite/) to a fresh repository to start the prototype.
This page stays in Ultra for reference and later integration.

## Reference verification

```sh
bin/phpunit src/StoredState/Tests/MappedTable/RewriteScenariosTest.php
bin/phpunit src/StoredState/Tests src/Projection/Tests/MappedProjectionPersistenceTest.php
php examples/mapped-table-sql/bin/benchmark.php
```

The [scenario verifier](../../src/StoredState/Tests/MappedTable/RewriteScenariosTest.php) applies the same
portable data to both current backends. It lives in Ultra because it uses the current interface and fixtures.
Set `ULTRA_TEST_POSTGRES_DSN` to use a different PostgreSQL test database. The
[reference runner](../../examples/mapped-table-sql/bin/benchmark.php) uses temporary tables on dedicated
sessions, validates each sample and emits JSON. It uses compiled PostgreSQL SQL metadata. It leaves
Symfony compilation and projection integration to their own verification.

| Reference area | Existing test evidence |
| --- | --- |
| Overlay, conditions and membership | [OptimisticConcurrencyTest](../../src/StoredState/Tests/MappedTable/OptimisticConcurrencyTest.php), [MembershipReconciliationTest](../../src/StoredState/Tests/MappedTable/MembershipReconciliationTest.php) |
| Allowed values and codecs | [ColumnValueRoundTripTest](../../src/StoredState/Tests/MappedTable/ColumnValueRoundTripTest.php) |
| Ordering and rollback | [FlushCoordinationTest](../../src/StoredState/Tests/MappedTable/FlushCoordinationTest.php), [FlushLifecycleTest](../../src/StoredState/Tests/MappedTable/FlushLifecycleTest.php) |
| Cooperative guards and intrinsic locks | [QueryGuardTest](../../src/StoredState/Tests/MappedTable/QueryGuardTest.php), [PersistenceLockingTest](../../src/StoredState/Tests/MappedTable/PersistenceLockingTest.php), [CooperativeLockingTest](../../src/StoredState/Tests/CooperativeLockingTest.php) |
| Shared wiring and private projection lifecycle | [DefinedRowSetsTest](../../src/StoredState/Tests/MappedTable/DefinedRowSetsTest.php), [ContainerIntegrationTest](../../src/StoredState/Tests/ContainerIntegrationTest.php), [MappedProjectionPersistenceTest](../../src/Projection/Tests/MappedProjectionPersistenceTest.php) |
| SQL behavior | [SqlInspectionTest](../../src/StoredState/Tests/MappedTable/SqlInspectionTest.php) |

Whole-table reset has concrete callers in the [Symfony event-sourcing example](../../examples/symfony-event-sourcing/src/Projection/OrderSummary.php)
and the [Compliance source projection](../../examples/compliance/src/SourceProjection.php).
The [manual query contract](../stored-state/manual-queries.md) describes StateStore's low-level guard use.
