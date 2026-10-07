<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\EventStore\Event;
use Ultra\Projection\MessageHandling\MessageHandlingProjectionActivator;
use Ultra\Projection\MessageHandling\ProjectionMessageHandler;
use Ultra\Projection\MessageHandling\RunProjectionSegment;
use Ultra\Projection\MessageHandling\WakeProjectionCoordinator;
use Ultra\Projection\Tests\Support\MessageHandlingTrackingProjection;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\RecordingMediator;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class ActivationDeliveryTest extends ProjectionTestCase
{
    public function test_activation_delivers_a_wakeup_or_a_specific_durable_worker_claim(): void
    {
        $mediator = new RecordingMediator();
        $activator = new MessageHandlingProjectionActivator($mediator, 'test-projection');

        $activator->signalWorkAvailable();
        $activator->activateWorker('projection-1', 42, 'worker-1');

        static::assertCount(2, $mediator->messages);
        static::assertInstanceOf(WakeProjectionCoordinator::class, $mediator->messages[0]);
        static::assertStringStartsWith('test-projection-', $mediator->messages[0]->workerId);

        static::assertEquals(new RunProjectionSegment('projection-1', 42, 'worker-1'), $mediator->messages[1]);
    }

    public function test_a_delivered_segment_message_catches_up_and_acknowledges_its_claim(): void
    {
        $projection = new MessageHandlingTrackingProjection();
        $container = $this
            ->projectionUltraLight()
            ->withService($projection, MessageHandlingTrackingProjection::class)
            ->withAlias('test.tracking_projection', MessageHandlingTrackingProjection::class)
            ->boot();
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

        $event = self::messageEvent('course-1', 'Intro to Projections');
        $eventStore->append([$event]);

        $runner->ensureInitialized($runtime);
        static::assertNotNull($runner->claimSegment($runtime->projectionId(), 0, 'worker-1'));

        $handler->handleRunProjectionSegment(new RunProjectionSegment($runtime->projectionId(), 0, 'worker-1'));

        static::assertSame(['course-1' => 'Intro to Projections'], $projection->names);
        static::assertSame([], $activity->pendingInSegment($runtime->projectionId(), 0, 255));
    }

    private static function messageEvent(string $courseId, string $name): Event
    {
        return new Event(
            eventType: 'message_handling_projection.course_created',
            payload: ['courseId' => $courseId, 'name' => $name],
            domainIds: ['courseId' => $courseId],
        );
    }
}
