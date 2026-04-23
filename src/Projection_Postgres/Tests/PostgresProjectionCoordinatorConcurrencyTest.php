<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorLeaseRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;

final class ProjectionConcurrencyWorker
{
    private string $stdout = '';
    private string $stderr = '';
    private string $stdoutRemainder = '';

    /** @var list<array<string, mixed>> */
    private array $messages = [];

    public function __construct(
        private Process $process,
        private ?InputStream $input = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function waitForEvent(string $eventName, int $timeoutMs): array
    {
        return $this->waitForMessage(
            static fn (array $message): bool => 'event' === ($message['type'] ?? null) && $eventName === ($message['name'] ?? null),
            $timeoutMs,
            sprintf('Timed out waiting for worker event "%s".', $eventName),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function waitForResult(int $timeoutMs): array
    {
        $this->awaitExit($timeoutMs);

        foreach (array_reverse($this->messages) as $message) {
            if ('result' === ($message['type'] ?? null)) {
                return $message + [
                    'exitCode' => $this->process->getExitCode() ?? -1,
                    'stdout' => $this->stdout,
                    'stderr' => $this->stderr,
                ];
            }
        }

        throw new \RuntimeException('Worker exited without emitting a result message. STDERR: '.$this->stderr.' STDOUT: '.$this->stdout);
    }

    public function send(string $input, bool $close = false): void
    {
        if (null === $this->input) {
            throw new \LogicException('This worker does not accept stdin.');
        }

        $this->input->write($input.PHP_EOL);

        if ($close) {
            $this->input->close();
        }
    }

    public function stop(): void
    {
        if (null !== $this->input && ! $this->input->isClosed()) {
            $this->input->close();
        }

        if ($this->process->isRunning()) {
            $this->process->stop(1);
        }

        $this->pump();
    }

    /**
     * @param \Closure(array<string, mixed>): bool $matches
     *
     * @return array<string, mixed>
     */
    private function waitForMessage(\Closure $matches, int $timeoutMs, string $timeoutMessage): array
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $this->pump();

            foreach ($this->messages as $message) {
                if ($matches($message)) {
                    return $message;
                }
            }

            if (! $this->process->isRunning()) {
                break;
            }

            $this->process->checkTimeout();
            usleep(10_000);
        }

        $this->pump();

        throw new \RuntimeException($timeoutMessage.' STDERR: '.$this->stderr.' STDOUT: '.$this->stdout);
    }

    private function awaitExit(int $timeoutMs): void
    {
        $previousTimeout = $this->process->getTimeout();
        $this->process->setTimeout($timeoutMs / 1000);

        try {
            $this->process->wait();
        } catch (ProcessTimedOutException $exception) {
            throw new \RuntimeException(
                'Timed out waiting for projection worker to exit. STDERR: '.$this->stderr.' STDOUT: '.$this->stdout,
                previous: $exception,
            );
        } finally {
            $this->process->setTimeout($previousTimeout);
        }

        $this->pump();
    }

    private function pump(): void
    {
        $stdoutChunk = $this->process->getIncrementalOutput();
        if ('' !== $stdoutChunk) {
            $this->stdout .= $stdoutChunk;
            $this->stdoutRemainder .= $stdoutChunk;
            $this->parseStdoutMessages();
        }

        $stderrChunk = $this->process->getIncrementalErrorOutput();
        if ('' !== $stderrChunk) {
            $this->stderr .= $stderrChunk;
        }
    }

    private function parseStdoutMessages(): void
    {
        while (false !== $position = strpos($this->stdoutRemainder, PHP_EOL)) {
            $line = substr($this->stdoutRemainder, 0, $position);
            $this->stdoutRemainder = substr($this->stdoutRemainder, $position + strlen(PHP_EOL));

            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($message)) {
                $this->messages[] = $message;
            }
        }
    }
}

#[CoversNothing]
#[Group('slow')]
#[Group('projection-concurrency')]
final class PostgresProjectionCoordinatorConcurrencyTest extends PostgresProjectionTestCase
{
    public function test_leader_and_follower_can_process_different_segments_concurrently(): void
    {
        $dsn = $this->postgresDsn();

        $this->withPostgresProjectionCoordinatorRuntime(function (
            \Doctrine\DBAL\Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segments,
            ProjectionRunner $runner,
            ProjectionBucketActivityRepositoryInterface $activity,
            PostgresProjectionCoordinatorLeaseRepository $leases,
            string $eventStorePrefix,
            string $projectionPrefix,
        ) use ($dsn): void {
            $tableName = $projectionPrefix.'course_names_view';
            [$leftCourseId, $rightCourseId] = self::courseIdsAcrossSplit();
            $leftBucket = BucketHasher::bucketFor($leftCourseId);
            $rightBucket = BucketHasher::bucketFor($rightCourseId);

            $connection->executeStatement(sprintf(
                'CREATE TABLE %s (
                    course_id TEXT PRIMARY KEY,
                    name      TEXT NOT NULL
                )',
                $tableName,
            ));

            $store->append([
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => $leftCourseId, 'name' => 'Math 101'],
                    domainIds: ['courseId' => $leftCourseId],
                ),
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => $rightCourseId, 'name' => 'Physics 201'],
                    domainIds: ['courseId' => $rightCourseId],
                ),
            ]);

            $segments->ensureInitialized('course_names');
            $segments->splitSegment('course_names', 0);
            $segments->reconcilePendingOperations('course_names');
            $activity->bump('course_names', $leftBucket);
            $activity->bump('course_names', $rightBucket);

            $leader = null;
            $follower = null;

            try {
                $leader = self::startProjectionWorker([
                    'dsn' => $dsn,
                    'eventStorePrefix' => $eventStorePrefix,
                    'projectionPrefix' => $projectionPrefix,
                    'tableName' => $tableName,
                    'workerId' => 'worker-a',
                    'pauseBeforeFirstLoad' => true,
                ], true);
                $leader->waitForEvent('segment_claimed', 5_000);

                $follower = self::startProjectionWorker([
                    'dsn' => $dsn,
                    'eventStorePrefix' => $eventStorePrefix,
                    'projectionPrefix' => $projectionPrefix,
                    'tableName' => $tableName,
                    'workerId' => 'worker-b',
                    'pauseBeforeFirstLoad' => false,
                ]);

                $followerResult = $follower->waitForResult(5_000);
                $follower = null;

                $leader->send('release', true);
                $leaderResult = $leader->waitForResult(5_000);
                $leader = null;

                self::assertSame(0, $leaderResult['exitCode'], $leaderResult['stderr']);
                self::assertSame('ok', $leaderResult['status']);
                self::assertTrue($leaderResult['acquiredLease']);
                self::assertSame(1, $leaderResult['processedEvents']);
                self::assertGreaterThanOrEqual(1, $leaderResult['acknowledgedBuckets']);

                self::assertSame(0, $followerResult['exitCode'], $followerResult['stderr']);
                self::assertSame('ok', $followerResult['status']);
                self::assertFalse($followerResult['acquiredLease']);
                self::assertSame(1, $followerResult['processedEvents']);
                self::assertGreaterThanOrEqual(1, $followerResult['acknowledgedBuckets']);

                $rows = $connection->fetchAllAssociative(
                    sprintf('SELECT course_id, name FROM %s ORDER BY course_id ASC', $tableName),
                );

                self::assertSame([
                    ['course_id' => $leftCourseId, 'name' => 'Math 101'],
                    ['course_id' => $rightCourseId, 'name' => 'Physics 201'],
                ], $rows);
                self::assertSame([], $activity->pendingBuckets('course_names'));
                self::assertFalse($leases->hasLiveCoordinator());
            } finally {
                $leader?->stop();
                $follower?->stop();
            }
        });
    }

    /**
     * @return array{string, string}
     */
    private static function courseIdsAcrossSplit(): array
    {
        $left = null;
        $right = null;

        for ($i = 0; $i < 1024; ++$i) {
            $courseId = 'course-'.$i;
            $bucket = BucketHasher::bucketFor($courseId);

            if (null === $left && $bucket < 128) {
                $left = $courseId;
            }

            if (null === $right && $bucket >= 128) {
                $right = $courseId;
            }

            if (null !== $left && null !== $right) {
                return [$left, $right];
            }
        }

        throw new \RuntimeException('Failed to find course ids that land on both sides of the initial split.');
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function startProjectionWorker(array $config, bool $interactive = false): ProjectionConcurrencyWorker
    {
        $script = __DIR__.'/Fixtures/postgres_projection_concurrency_worker.php';
        $input = $interactive ? new InputStream() : null;
        $process = new Process(
            [PHP_BINARY, $script],
            dirname(__DIR__, 3),
            [
                'ULTRA_WORKER_CONFIG' => base64_encode(json_encode($config, JSON_THROW_ON_ERROR)),
            ],
            $input,
        );
        $process->setTimeout(15);
        $process->start();

        return new ProjectionConcurrencyWorker($process, $input);
    }
}
