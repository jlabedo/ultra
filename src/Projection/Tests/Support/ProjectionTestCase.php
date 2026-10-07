<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\Time\ClockInterface;

require_once __DIR__ . '/Fixtures.php';

abstract class ProjectionTestCase extends ProjectionFunctionalTestCase
{
    /** @var list<ProjectionFixture> */
    private array $fixtures = [];

    public static function backends(): iterable
    {
        yield 'memory' => ['memory'];
        yield 'postgres' => ['postgres'];
    }

    protected function fixture(string $backend, ?ClockInterface $clock = null): ProjectionFixture
    {
        $fixture = new ProjectionFixture($backend, $clock);
        $this->fixtures[] = $fixture;

        return $fixture;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->fixtures) as $fixture) {
            $fixture->close();
        }
        parent::tearDown();
    }

    protected static function courseCreatedEvent(string $courseId, string $name): Event
    {
        return new Event(
            'runner.course.created',
            ['courseId' => $courseId, 'name' => $name],
            ['courseId' => $courseId],
        );
    }

    protected static function courseIdInBucketRange(int $from, int $to): string
    {
        for ($i = 0; $i < 10_000; ++$i) {
            $id = 'projection-guarantees-course-' . $i;
            $bucket = BucketHasher::bucketFor($id);
            if ($bucket >= $from && $bucket <= $to) {
                return $id;
            }
        }

        throw new \RuntimeException(sprintf('No course ID found in bucket range [%d-%d].', $from, $to));
    }
}
