# Testing guarantees

| Suite | Observable guarantee |
| --- | --- |
| [WireProtocolTest](Concurrent/WireProtocolTest.php) | Known messages preserve their fields in one JSON frame; malformed frames and unsupported message classes report protocol errors. |
| [CheckpointCoordinationTest](Concurrent/CheckpointCoordinationTest.php) | Workers wait at named checkpoints, exchange payloads and injected values, remain independent, and return outcomes in spawn order despite reverse completion. |
| [WorkerLifecycleTest](Concurrent/WorkerLifecycleTest.php) | Real child processes return values or exception descriptions, report logs and stderr, respect waiting deadlines, and disappear after abort, stop or cluster destruction. |
| [ToolExecutionTest](ToolExecutionTest.php) | The PHPUnit proxy preserves interpreter options, arguments, output and passing or failing exit status. |
| [ContainerSetupTest](ContainerSetupTest.php) | Derived UltraLight setup preserves registration, leaves the base setup reusable, selects the requested container factory, and rejects duplicate service identities. |
| [PostgresFixtureIsolationTest](PostgresFixtureIsolationTest.php) | Shared connections retain identity while reset rolls back every nested transaction and releases session advisory locks. Named sessions and fresh connections use independent backends; resetting one session preserves another's transaction and locks. |
| [SqlTraceTest](SqlTraceTest.php) | Real PDO and native PostgreSQL calls preserve results, affected rows and exceptions while exposing parameters, types, phases, transaction events and failing SQL. |

The process suites belong to the `slow` group. The PID observations require the POSIX extension. Deadline checks begin after the child reaches a checkpoint, so startup time does not determine the asserted deadline.

The former Codec round trips share the frame provider, which checks encoding against an independent wire fixture and decodes that fixture. Worker checkpoint, payload, injection and nonblocking outcome cases share the sequential-checkpoint scenario. Cluster broadcast and independence cases share one scenario; spawn order and mixed outcomes share a reverse-completion scenario. The former simulated concurrency conflict repeats the mixed-outcome guarantee; actual storage races belong to EventStore, Projection and Coordination. Abort and cluster-cleanup cases now observe the disappearance of a real PID instead of asserting a constant.

WorkerRuntime, the bootstrap and the global checkpoint helper execute in child interpreters. PHPUnit coverage collected in the parent does not include those executions, and Infection's parent IncludeInterceptor does not propagate to children. These suites verify their process behavior but do not claim mutation coverage for the child code. The proxy's subprocess assertions likewise verify the real tooling path without adding root tooling to the Testing mutation boundary.

Shared setup is in [ConcurrentTestCase](Support/ConcurrentTestCase.php); assertions remain in the guarantee suites. General audit and refactoring rules are in [development verification](../../../docs/development.md#refactoring-tests).
