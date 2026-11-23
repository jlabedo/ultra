# MappedTable performance reference

Use one PostgreSQL connection and a dedicated temporary table with columns `owner TEXT`, `id INTEGER`
and `status TEXT NOT NULL`, primary key `(owner, id)). Declare `owner` as the collection key and prepare
all static mapping information ahead of the measured run. Reset and seed the table outside each sample.

| Workload | Action and expected final state |
| --- | --- |
| `insert-1000` | From empty storage, buffer 1,000 explicit inserts for owner A with IDs 1 through 1,000 and status `paid`. Run one flush. All rows should be present. |
| `unchanged-250` | Seed IDs 1 through 250 for A as `draft`. For every row observe only status and set it to the observed value. Flush and verify that all 250 rows still have that value. Check the SQL trace for zero writes. |
| `reconcile-200` | Seed 100 draft items for each of A and B. Observe A's keys. Reconcile A to IDs 1 through 50 with status `paid`, reconcile B to empty and flush. Only the 50 paid items for A should remain. |
| `guarded-100` | Observe a guarded SQL count for owner A in an empty table. Insert 100 paid items for A in the same unit of work. During flush acquire the guard and replay the count before the inserts. Verify the 100 paid items. |

Run sequentially on an idle database with five warmups and 30 measured operations per workload. Measure
wall time from the start of the decision through transaction completion and clearing the buffer. Include all
storage calls during that operation. Run fixture setup, verification and connection setup outside the timer.
Record the additional PHP allocation at its peak during each sample, relative to the amount immediately
before the decision. Report medians and the nearest-rank p95 across samples.

Trace a separate execution per workload. Count executed SQL statements, excluding transaction calls and
fixture statements. Report the number of INSERT, UPDATE, DELETE and SELECT statements, and the number
of bound parameters in the largest write. For the homogeneous inserts, three parameters per row gives
the actual batch size. Count a statement that checks row versions or takes a lock as work, even if it
affects no rows.

Verify final state and count statements before interpreting timings. The measured values are a local
comparison point. Use the same PHP, driver, PostgreSQL settings and machine for a head-to-head,
particularly when latency differences are small. A material change in SQL work should be explained by a
guarantee or measured benefit. Check that unchanged rows still cause zero writes and that homogeneous
inserts still batch. Cold container compilation and contention are separate from this latency sample.

## Measured reference

Ultra commit `be6e907`, PHP 8.5.12 on macOS arm64 (Apple M2, CLI OPcache disabled),
DBAL 4.6.0 with PDO PostgreSQL, PostgreSQL 18.6 in the local Docker container.

| Workload | SELECT | INSERT | UPDATE | DELETE | Largest write parameters | Median (ms) | p95 (ms) | Median peak PHP increase (bytes) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `insert-1000` | 1 | 1 | 0 | 0 | 3000 | 26.453 | 31.529 | 2060072 |
| `unchanged-250` | 251 | 0 | 0 | 0 | 0 | 55.992 | 66.946 | 817808 |
| `reconcile-200` | 4 | 1 | 0 | 2 | 150 | 4.504 | 4.945 | 252296 |
| `guarded-100` | 4 | 1 | 0 | 0 | 300 | 3.619 | 3.789 | 233376 |

The homogeneous insert completes as a single batch. The unchanged workload makes one storage read per
observation and one combined check at flush. These runs use temporary tables and a local database, so
the latency figures omit regular-table WAL costs and production network latency.

## If deferred foreign keys are evaluated

Add a workload with the same indexed parent and child tables and the same `DEFERRABLE INITIALLY IMMEDIATE`
foreign key in both runs. Seed existing parents and children outside the timer. In one transaction,
remove batches of those parents and children and add batches of new pairs. Compare:

- Immediate validation with the required parent/child write order.
- Deferred validation with a stable table order, including the named `SET CONSTRAINTS` call in the sample.

Use the same final rows and batch sizes. Include commit in the total wall time and report it separately
to show where validation happens. Record SQL executions and take separate two-session measurements for
lock waits and deadlocks under contention. The single-table reference above has no foreign key.
