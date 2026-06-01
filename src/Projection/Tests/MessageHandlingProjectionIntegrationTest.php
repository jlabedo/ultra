<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\Event;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\MessageHandling\MessageHandlingProjectionActivator;
use Ultra\Projection\MessageHandling\ProjectionMessageHandler;
use Ultra\Projection\MessageHandling\RunProjectionSegment;
use Ultra\Projection\MessageHandling\WakeProjectionCoordinator;
use Ultra\Projection\Tests\Support\ProjectionFunctionalTestCase;

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
final class MessageHandlingProjectionIntegrationTest extends ProjectionFunctionalTestCase
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
        $container = $this->projectionUltraLight()
            ->withService($projection, MessageHandlingTrackingProjection::class)
            ->withAlias('test.tracking_projection', MessageHandlingTrackingProjection::class)
            ->boot()
        ;
        $runtime = $container->projectionCatalog()->runtime('message_handling_tracking_projection');
        $eventStore = $container->eventStore();
        $activity = $container->projectionActivity();
        $runner = $container->projectionRunner();
        $handler = new ProjectionMessageHandler(
            $container->projectionCatalog(),
            $runner,
            $container->projectionCoordinator(),
            batchSize: 1,
            maxCycles: 3,
        );

        $event = self::courseCreatedEvent('course-1', 'Intro to Projections');
        $eventStore->append([$event]);

        $runner->ensureInitialized($runtime);
        self::assertNotNull($runner->claimSegment($runtime->projectionId(), 0, 'worker-1'));

        $handler->handleRunProjectionSegment(new RunProjectionSegment($runtime->projectionId(), 0, 'worker-1'));

        self::assertSame(['course-1' => 'Intro to Projections'], $projection->names);
        self::assertSame([], $activity->pendingInSegment($runtime->projectionId(), 0, 255));
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
