<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\Coordination\InMemory\InMemoryDirtyKeyTracker;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Time\Duration;

#[EventAttribute('student.subscribed')]
final readonly class StudentSubscribed
{
    public function __construct(
        public string $studentId,
        public string $courseId,
    ) {}
}

#[EventAttribute('course.created')]
final readonly class CourseCreatedEvent
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'test_projection', partitionBy: 'courseId')]
final class TestProjectionClass
{
    public bool $initialized = false;

    public int $flushes = 0;

    public bool $reset = false;

    /** @var list<string> */
    public array $handled = [];

    #[ProjectionInitializer]
    public function initialize(): void
    {
        $this->initialized = true;
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        ++$this->flushes;
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->reset = true;
    }

    #[ProjectionHandler]
    public function onStudentSubscribed(StudentSubscribed $event): void
    {
        $this->handled[] = 'subscribed:' . $event->studentId;
    }

    #[ProjectionHandler]
    public function onCourseCreated(CourseCreatedEvent $event): void
    {
        $this->handled[] = 'created:' . $event->courseId;
    }
}

#[Projection(name: 'no_init_projection', partitionBy: 'courseId')]
final class TestProjectionClassWithoutInitializer
{
    #[ProjectionHandler]
    public function onStudentSubscribed(StudentSubscribed $event): void {}
}

#[EventAttribute('student.subscribed')]
final readonly class DuplicateStudentSubscribed
{
    public function __construct(
        public string $studentId,
    ) {}
}

final readonly class EventWithoutAttribute
{
    public function __construct(
        public string $courseId,
    ) {}
}

#[Projection(name: 'invalid_builtin_projection', partitionBy: 'courseId')]
final class BuiltinParameterProjection
{
    #[ProjectionHandler]
    public function onInvalid(string $eventType): void {}
}

#[Projection(name: 'invalid_parameter_count_projection', partitionBy: 'courseId')]
final class InvalidParameterCountProjection
{
    #[ProjectionHandler]
    public function onInvalid(StudentSubscribed $event, CourseCreatedEvent $other): void {}
}

#[Projection(name: 'duplicate_handler_projection', partitionBy: 'courseId')]
final class DuplicateHandlerProjection
{
    #[ProjectionHandler]
    public function onStudentSubscribed(StudentSubscribed $event): void {}

    #[ProjectionHandler]
    public function onDuplicateStudentSubscribed(DuplicateStudentSubscribed $event): void {}
}

#[Projection(name: 'missing_event_attribute_projection', partitionBy: 'courseId')]
final class MissingEventAttributeProjection
{
    #[ProjectionHandler]
    public function onMissingAttribute(EventWithoutAttribute $event): void {}
}

#[Projection(name: 'invalid_initializer_projection', partitionBy: 'courseId')]
final class InvalidInitializerProjection
{
    #[ProjectionInitializer]
    public function initialize(string $projectionId): void {}
}

#[Projection(name: 'invalid_flush_projection', partitionBy: 'courseId')]
final class InvalidFlushProjection
{
    #[ProjectionFlush]
    public function flush(string $projectionId): void {}
}

#[Projection(name: 'invalid_resetter_projection', partitionBy: 'courseId')]
final class InvalidResetterProjection
{
    #[ProjectionResetter]
    public function reset(string $projectionId): void {}
}

final class CallbackEventStore implements EventStore
{
    public function __construct(
        private readonly EventStore $inner,
        private ?\Closure $beforeLoad = null,
    ) {}

    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        if (null !== $this->beforeLoad) {
            ($this->beforeLoad)();
            $this->beforeLoad = null;
        }

        return $this->inner->load($query, $from, $to, $batchSize);
    }

    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        $this->inner->append($events, ...$appendConditions);
    }
}

#[EventAttribute('runner.course.created')]
final readonly class RunnerCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'tracking_projection', partitionBy: 'courseId')]
final class TrackingProjection
{
    public int $initialized = 0;

    /** @var list<string> */
    public array $applied = [];

    /** @var array<string, string> */
    public array $names = [];

    public int $flushes = 0;

    public int $resets = 0;

    #[ProjectionInitializer]
    public function initialize(): void
    {
        ++$this->initialized;
    }

    #[ProjectionHandler]
    public function onCourseCreated(RunnerCourseCreated $event): void
    {
        $this->applied[] = $event->name;
        $this->names[$event->courseId] = $event->name;
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        ++$this->flushes;
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        ++$this->resets;
        $this->names = [];
        $this->applied = [];
    }
}

#[Projection(name: 'failing_tracking_projection', partitionBy: 'courseId')]
final class FailingTrackingProjection
{
    public int $initialized = 0;

    #[ProjectionInitializer]
    public function initialize(): void
    {
        ++$this->initialized;
    }

    #[ProjectionHandler]
    public function onCourseCreated(RunnerCourseCreated $event): void
    {
        throw new \RuntimeException('Projection handler failed.');
    }
}

#[EventAttribute('course.created')]
final readonly class RuntimeCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'runtime_projection', partitionBy: 'courseId')]
final class RuntimeProjection
{
    /** @var array<string, string> */
    public array $names = [];

    #[ProjectionHandler]
    public function onCourseCreated(RuntimeCourseCreated $event): void
    {
        $this->names[$event->courseId] = $event->name;
    }
}

#[Projection(name: 'secondary_runtime_projection', partitionBy: 'courseId')]
final class SecondaryRuntimeProjection
{
    #[ProjectionHandler]
    public function onCourseCreated(RuntimeCourseCreated $event): void {}
}

#[EventAttribute('coordinator.course.created')]
final readonly class CoordinatorCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'coordinator_projection', partitionBy: 'courseId')]
final class CoordinatorProjection
{
    /** @var array<string, string> */
    public array $names = [];

    #[ProjectionHandler]
    public function onCourseCreated(CoordinatorCourseCreated $event): void
    {
        $this->names[$event->courseId] = $event->name;
    }
}

final class CountingLeaseRepository implements CoordinatorLease
{
    public int $acquireCalls = 0;
    public int $renewCalls = 0;
    public int $releaseCalls = 0;

    public function acquire(
        string $coordinatorKey,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): bool {
        ++$this->acquireCalls;

        return true;
    }

    public function renew(
        string $coordinatorKey,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): void {
        ++$this->renewCalls;
    }

    public function release(string $coordinatorKey, string $workerId): void
    {
        ++$this->releaseCalls;
    }

    public function hasLiveCoordinator(string $coordinatorKey): bool
    {
        return false;
    }

    public function markWakeEnqueued(string $coordinatorKey, Duration $ttl = new Duration(seconds: 30)): bool
    {
        return true;
    }
}

/**
 * Spies on the generic tracker calls a ProjectionActivity makes: `pending()`
 * backs `affected()`, `pendingForChannel()` backs `pendingInSegment()`.
 */
final class CountingDirtyKeyTracker implements DirtyKeyTracker
{
    public int $pendingCalls = 0;
    public int $pendingForChannelCalls = 0;

    public function __construct(
        private readonly DirtyKeyTracker $inner = new InMemoryDirtyKeyTracker(),
    ) {}

    public function bump(string $channel, string $key): void
    {
        $this->inner->bump($channel, $key);
    }

    public function pending(int $limit = 256): array
    {
        ++$this->pendingCalls;

        return $this->inner->pending($limit);
    }

    public function pendingForChannel(string $channel, int $limit = 256): array
    {
        ++$this->pendingForChannelCalls;

        return $this->inner->pendingForChannel($channel, $limit);
    }

    public function drainSeen(string $channel, array $seen): int
    {
        return $this->inner->drainSeen($channel, $seen);
    }
}

final class RecordingMediator implements MediatorInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function send(object $message, array|MessageContext $context = []): mixed
    {
        $this->messages[] = $message;

        return null;
    }
}

#[EventAttribute('message_handling_projection.course_created')]
final readonly class MessageHandlingProjectionCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'message_handling_tracking_projection', partitionBy: 'courseId')]
final class MessageHandlingTrackingProjection
{
    /** @var array<string, string> */
    public array $names = [];

    #[ProjectionHandler]
    public function onCourseCreated(MessageHandlingProjectionCourseCreated $event): void
    {
        $this->names[$event->courseId] = $event->name;
    }
}

#[EventAttribute('projection.stubbed')]
final readonly class StubProjectionEvent {}

#[Projection(name: 'stub_projection', partitionBy: 'id')]
final class StubProjection
{
    #[ProjectionHandler]
    public function onSomething(StubProjectionEvent $event): void {}
}

#[Projection(name: 'other_stub_projection', partitionBy: 'id')]
final class OtherStubProjection
{
    #[ProjectionHandler]
    public function onSomething(StubProjectionEvent $event): void {}
}

#[Projection(name: 'third_stub_projection', partitionBy: 'id')]
final class ThirdStubProjection
{
    #[ProjectionHandler]
    public function onSomething(StubProjectionEvent $event): void {}
}

#[EventAttribute('course.created')]
final readonly class ConcurrencyCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'course_names', partitionBy: 'courseId')]
final class ConcurrencyCourseNamesProjection
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
    ) {}

    #[ProjectionInitializer]
    public function initialize(): void
    {
        $this->connection->executeStatement(sprintf('CREATE TABLE IF NOT EXISTS %s (
                course_id TEXT PRIMARY KEY,
                name      TEXT NOT NULL
            )', $this->tableName));
    }

    #[ProjectionHandler]
    public function onCourseCreated(ConcurrencyCourseCreated $event): void
    {
        $this->connection->executeStatement(
            sprintf('INSERT INTO %s (course_id, name) VALUES (?, ?)
                 ON CONFLICT (course_id) DO UPDATE SET name = EXCLUDED.name', $this->tableName),
            [$event->courseId, $event->name],
        );
    }
}
