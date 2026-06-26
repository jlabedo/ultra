<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Bundle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Bundle\Command\BackfillProjectionSegmentCommand;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentStore;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\BackfillProjection;

/**
 * @internal
 */
#[CoversClass(BackfillProjectionSegmentCommand::class)]
final class BackfillProjectionSegmentCommandTest extends TestCase
{
    public function test_drains_the_segment_until_caught_up(): void
    {
        $projection = new BackfillProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentStore();
        $runner = new ProjectionRunner($eventStore, $segments);
        $runner->ensureInitialized($runtime);
        $eventStore->append([
            new Event(
                eventType: 'backfill.course.created',
                payload: ['courseId' => 'course-1'],
                domainIds: ['courseId' => 'course-1'],
            ),
            new Event(
                eventType: 'backfill.course.created',
                payload: ['courseId' => 'course-2'],
                domainIds: ['courseId' => 'course-2'],
            ),
        ]);

        $tester = new CommandTester(new BackfillProjectionSegmentCommand(new ProjectionCatalog($runtime), $runner));

        self::assertSame(0, $tester->execute([
            'projection-id' => 'backfill_projection',
            'bucket-from' => '0',
            '--worker-id' => 'worker-1',
            '--limit' => '1',
        ]));
        self::assertSame(['course-1', 'course-2'], $projection->courseIds);
        self::assertStringContainsString('caught up after 2 event(s)', $tester->getDisplay());
    }

    public function test_returns_two_when_the_segment_is_claimed_by_another_worker(): void
    {
        $runtime = new ProjectionRuntime(new BackfillProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentStore();
        $runner = new ProjectionRunner($eventStore, $segments);
        $runner->ensureInitialized($runtime);
        self::assertNotNull($segments->claimSegment('backfill_projection', 0, 'worker-1'));

        $tester = new CommandTester(new BackfillProjectionSegmentCommand(new ProjectionCatalog($runtime), $runner));

        self::assertSame(2, $tester->execute([
            'projection-id' => 'backfill_projection',
            'bucket-from' => '0',
            '--worker-id' => 'worker-2',
        ]));
        self::assertStringContainsString('could not be claimed', $tester->getDisplay());
    }
}
