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
use Ultra\Projection\InMemory\InMemoryProjectionBucketActivityRepository;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\MessageHandling\MessageHandlingProjectionActivator;
use Ultra\Projection\MessageHandling\ProjectionMessageHandler;
use Ultra\Projection\MessageHandling\WakeProjectionWork;
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

#[CoversClass(MessageHandlingProjectionActivator::class)]
#[CoversClass(ProjectionMessageHandler::class)]
#[CoversClass(WakeProjectionWork::class)]
final class MessageHandlingProjectionIntegrationTest extends TestCase
{
    public function test_activator_dispatches_a_coarse_wake_message_through_the_mediator(): void
    {
        $mediator = new RecordingMediator();
        $activator = new MessageHandlingProjectionActivator($mediator, 'test-projection');

        $activator->signalWorkAvailable();

        self::assertCount(1, $mediator->messages);
        self::assertInstanceOf(WakeProjectionWork::class, $mediator->messages[0]);
        self::assertStringStartsWith('test-projection-', $mediator->messages[0]->workerId);
    }

    public function test_wake_message_runs_claimable_projection_work(): void
    {
        $projection = new MessageHandlingTrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity);
        $handler = new ProjectionMessageHandler($coordinator, batchSize: 1, maxCycles: 3);

        $event = self::courseCreatedEvent('course-1', 'Intro to Projections');
        $eventStore->append([$event]);
        $bucket = $runtime->definition()->bucketFor($event);
        self::assertNotNull($bucket);
        $activity->bump($runtime->projectionId(), $bucket);

        $handler->handleWakeProjectionWork(new WakeProjectionWork('worker-1'));

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
