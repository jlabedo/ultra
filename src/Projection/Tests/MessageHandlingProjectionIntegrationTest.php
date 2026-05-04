<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\InMemory\InMemoryProjectionBucketActivityRepository;
use Ultra\Projection\InMemory\InMemoryProjectionCoordinatorLeaseRepository;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\MessageHandling\MessageHandlingProjectionActivator;
use Ultra\Projection\MessageHandling\ProjectionMessageHandler;
use Ultra\Projection\MessageHandling\RunProjectionSegment;
use Ultra\Projection\MessageHandling\WakeProjectionCoordinator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;

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

/**
 * @internal
 */
#[CoversClass(MessageHandlingProjectionActivator::class)]
#[CoversClass(ProjectionMessageHandler::class)]
#[CoversClass(RunProjectionSegment::class)]
#[CoversClass(WakeProjectionCoordinator::class)]
final class MessageHandlingProjectionIntegrationTest extends TestCase
{
    public function test_activator_dispatches_wake_and_segment_messages_through_the_mediator(): void
    {
        $mediator = new RecordingMediator();
        $activator = new MessageHandlingProjectionActivator($mediator, 'test-projection');

        $activator->signalWorkAvailable();
        $activator->activateWorker('projection-1', 42, 'worker-1');

        self::assertCount(2, $mediator->messages);
        self::assertInstanceOf(WakeProjectionCoordinator::class, $mediator->messages[0]);
        self::assertStringStartsWith('test-projection-', $mediator->messages[0]->workerId);

        self::assertEquals(
            new RunProjectionSegment('projection-1', 42, 'worker-1'),
            $mediator->messages[1],
        );
    }

    public function test_projection_segment_message_runs_the_claimed_projection_segment(): void
    {
        $projection = new MessageHandlingTrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, new InMemoryProjectionActivator());
        $handler = new ProjectionMessageHandler($catalog, $runner, $coordinator, batchSize: 1, maxCycles: 3);

        $event = self::courseCreatedEvent('course-1', 'Intro to Projections');
        $eventStore->append([$event]);
        $bucket = $runtime->definition()->bucketFor($event);
        self::assertNotNull($bucket);
        $activity->bump($runtime->projectionId(), $bucket);

        $runner->ensureInitialized($runtime);
        self::assertNotNull($runner->claimSegment($runtime->projectionId(), 0, 'worker-1'));

        $handler->handleRunProjectionSegment(new RunProjectionSegment($runtime->projectionId(), 0, 'worker-1'));

        self::assertSame(['course-1' => 'Intro to Projections'], $projection->names);
        self::assertSame([], $activity->pendingBucketsForSegment($runtime->projectionId(), 0, 255));
    }

    private static function courseCreatedEvent(string $courseId, string $name): Event
    {
        return new Event(
            eventType: 'message_handling_projection.course_created',
            payload: ['courseId' => $courseId, 'name' => $name],
            domainIds: ['courseId' => $courseId],
        );
    }
}
