# MappedTable rewrite brief

Start in a fresh repository with this brief, the [scenario data](scenarios.json) and the
[performance protocol](performance.md). Develop the interface against the observable behavior below.
The first prototype should run directly with in-memory storage and PostgreSQL. Add container compilation
next, then validate integration with the application unit of work and projection lifecycle.

## Observable contract

**Definitions and assembly.** A row set names one physical table, its columns, their normalized PHP
representations and nullability, and the complete immutable key. Two consumers of one definition within
the same unit of work see the same pending changes. A collection fixes the declared owner components of
its item key. Distinct owners can have items with the same remaining key. The container rejects invalid
key and owner declarations, incompatible PostgreSQL conversions and invalid guard dependencies during
compilation. A design that uses declared dependencies for immediate foreign keys also validates missing
parents and cycles and prepares the order at compilation. Prepare dependency indexes and reusable SQL as
far as the implementation permits. Definitions can be evaluated at compilation without application services.

**Row reads and conditions.** Read a line by complete key, with an optional selection of columns. An
omitted selection reads all available mapped columns. An empty selection reads existence and the complete
key. Pending changes overlay stored values. An unknown value is omitted and a known null remains null.
An observation returns that same view and can protect presence or absence. It records value conditions
only for returned values obtained from storage. Later observations of pending values preserve earlier storage
conditions. A contradictory expectation about a locally known value fails during preparation. Condition-only
work is checked on flush, including an update that sets unchanged observed values.

**Collections.** Observing membership protects the set of complete keys independently of item values.
Reconciliation selects the desired keys for one owner, sets supplied values and leaves unspecified
values on retained rows intact. An empty reconciliation clears the owner's collection. The buffered view
reflects each reconciliation immediately. A later reconciliation for the same owner replaces the previous
desired membership. Later explicit writes can add or remove members. An earlier observation of stored keys
survives reconciliation, including an observation of an empty collection. A reconciliation without such an
observation imposes the desired set even if additional rows arrived before the cleanup.

**Transaction and lifecycle.** Decisions prepare work before the transaction opens. A flush runs inside its
caller's transaction. Related parent and child changes commit atomically. Owners have immutable key
components. Cleanup is explicit. PostgreSQL must reject a committed state that leaves a child without its
referenced parent. A design supporting immediate foreign keys must keep each statement valid. With a
deferred foreign key, validation can occur at commit. A failed condition or foreign key check rolls back
the batch, including earlier successful statements. A successful flush consumes its work. The runner
clears queued work after an error or rollback so it can be reused. A projection's private tables flush at
its lifecycle boundary in the transaction that applies the projection and any checkpoint update. An
application table has one flush owner.

**Query guards.** A repeatable storage query can be observed before local writes that affect its guard value.
During flush, acquire the required cooperative locks in stable order and replay the query before applying
row writes. A changed result triggers a conflict. Writers through the row sets participate, including direct
writes and empty owner reconciliation. When a writer changes the column that identifies a guard value, protect
both old and new values. Reject operations whose affected guard values cannot be determined. A query read
following an incompatible local mutation fails before the query runs. Locks, replay and writes use the same
transaction resource. Independently written SQL needs to participate in the same protocol for these guarantees.

## Executable scenario data

Each entry in [scenarios.json](scenarios.json) is an independent scenario starting with an empty table and
a clean unit of work. The key is `(tenant, owner, id)`, all strings, with tenant `shop` and owner key
`(tenant, owner)`. The two nullable text values are `status` and `note`. An address such as `a/line`
selects item `line` of owner `a`. Values on a `seed` operation describe a complete committed row apart
from its key, with omitted nullable columns treated like storage defaults. `seed` between preparation and
flush represents an independently committed change with no in-flight contention.

| Operation | Behavior exercised |
| --- | --- |
| `read`, `observe`, `stored` | Compare the requested projection with `returns`. `stored` reads the database independently of the buffered view. JSON object member order has no meaning. |
| `change`, `upsert` | Buffer the given values for an existing row or allow creation as well, respectively. |
| `keys`, `observeKeys` | Read item IDs for the specified owner, comparing membership by sorting the IDs. Only `observeKeys` records the membership condition. |
| `reconcile` | Supply the desired items for the owner as an object keyed by item ID. |
| `flush` | Run the prepared work in a transaction. Assert `ok` or a concurrency `conflict`, then assert that the runner has no pending work. |

Keep the executable scenarios small and shared by the storage implementations. The following cases also
need functional coverage through the prototype's interface. They require a second table, two concurrent
sessions or a real container.

| Case | Initial state, action and result | Run on |
| --- | --- | --- |
| Parent and child in one transaction | Replace an old parent and its last child with a new parent and child. Supply the operations in an order that requires either scheduling or deferred validation. The old rows are removed and the new relationship exists. Omitting cleanup on a parent with a child fails and rolls back. The current reference uses an immediate foreign key. If the prototype uses deferred keys, also test a failure at commit and reuse of the runner afterward. | PostgreSQL, with the successful case also in memory |
| Guards before replay | Observe an aggregate for owner A. Commit a participating write for A before flush. Replay sees the new value, rejects the decision and leaves local writes unapplied. A pending incompatible local write also rejects an initial observation before its callback runs. | Memory and PostgreSQL |
| Empty reconciliation under a guard | A reader holds the guard for owner A. From another session, reconcile A to empty with zero buffered row writes. It waits for the reader's lock. A writer for a different owner can proceed. Check lock waits explicitly with two sessions and a bounded timeout. | PostgreSQL |
| Locking an observed absence | Flush an observation of a missing key and keep the transaction open. A participating writer trying to insert that key waits until the observer's transaction ends. Independently verify that inserting before the observer's flush causes an optimistic conflict. | PostgreSQL |
| Container and projection lifecycle | Compile two consumers of the same definition and verify their shared pending view. Reject conflicting or invalid ownership at compilation. Run one projection batch, fail a later batch after buffering a change, and verify that a retry starts clean and that writes and checkpoint roll back together. | Symfony, including PostgreSQL transaction integration |

## Decisions to revisit

- Whether PostgreSQL must support immediate foreign keys between tables written in the same flush.
  Requiring `DEFERRABLE` foreign keys with `NO ACTION` would allow a stable write order independent
  of parent/child dependencies. It would impose a schema requirement and permit a temporarily invalid
  relationship between statements in the same transaction. Decide where integrity must hold, including
  when another unit of work or projection runs in that transaction, and which integrity checks the
  in-memory implementation must provide.
- Whether the application needs a manual construction route without definitions. In-memory and PostgreSQL
  execution still need one way to assemble the same declared behavior without Symfony for tests.
- Whether whole-table operations need to expose general reconciliation and membership observations. A
  whole-table reset is used by existing projections.
- Whether to keep manually registered locks and raw query conditions alongside named guards, and at
  which interface. If retained, their transaction and ordering guarantees need explicit tests.
- Whether check-only absence protection needs a per-table switch. Measure its cost separately before
  choosing a default or a configuration surface.

Treat these as choices about scope. Preserve functional guarantees for each capability the prototype retains.
