<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Bundle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Ultra\Projection\Bundle\Command\ListSegmentsCommand;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;

#[CoversClass(ListSegmentsCommand::class)]
final class ListSegmentsCommandTest extends TestCase
{
    public function test_lists_segments_from_the_projection_segment_repository(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('course_names');
        $repository->splitSegment('course_names', 0);
        self::assertNotNull($repository->claimSegment('course_names', 128, 'worker-1'));

        $tester = new CommandTester(new ListSegmentsCommand($repository));

        self::assertSame(0, $tester->execute(['projection-id' => 'course_names']));

        $output = $tester->getDisplay();
        self::assertStringContainsString('bucket_from', $output);
        self::assertStringContainsString('bucket_to', $output);
        self::assertStringContainsString('0', $output);
        self::assertStringContainsString('127', $output);
        self::assertStringContainsString('128', $output);
        self::assertStringContainsString('255', $output);
        self::assertStringContainsString('worker-1', $output);
    }

    public function test_reports_when_a_projection_has_no_segments(): void
    {
        $tester = new CommandTester(new ListSegmentsCommand(new InMemoryProjectionSegmentRepository()));

        self::assertSame(0, $tester->execute(['projection-id' => 'course_names']));

        self::assertStringContainsString('No segments found for "course_names".', $tester->getDisplay());
    }
}
