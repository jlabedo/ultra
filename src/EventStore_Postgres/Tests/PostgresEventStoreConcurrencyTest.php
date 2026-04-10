<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\Tests\Support\PostgresEventStoreTestCase;

use function Ultra\EventStore_Postgres\Tests\postgresTestSupportedImplementations;

final class ConcurrencyWorker
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

    public function isRunning(): bool
    {
        $this->pump();

        return $this->process->isRunning();
    }

    /**
     * @return array<string, mixed>
     */
    public function waitForEvent(string $eventName, int $timeoutMs): array
    {
        return $this->waitForMessage(
            static fn(array $message): bool => 'event' === ($message['type'] ?? null) && $eventName === ($message['name'] ?? null),
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

        throw new \RuntimeException('Worker exited without emitting a result message. STDERR: ' . $this->stderr . ' STDOUT: ' . $this->stdout);
    }

    public function send(string $input, bool $close = false): void
    {
        if (null === $this->input) {
            throw new \LogicException('This worker does not accept stdin.');
        }

        $this->input->write($input . PHP_EOL);

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

        throw new \RuntimeException($timeoutMessage . ' STDERR: ' . $this->stderr . ' STDOUT: ' . $this->stdout);
    }

    private function awaitExit(int $timeoutMs): void
    {
        $previousTimeout = $this->process->getTimeout();
        $this->process->setTimeout($timeoutMs / 1000);

        try {
            $this->process->wait();
        } catch (ProcessTimedOutException $exception) {
            throw new \RuntimeException(
                'Timed out waiting for concurrency worker to exit. STDERR: ' . $this->stderr . ' STDOUT: ' . $this->stdout,
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

/**
 * @internal
 */
#[CoversNothing]
#[Group('slow')]
#[Group('event-store-concurrency')]
final class PostgresEventStoreConcurrencyTest extends PostgresEventStoreTestCase
{
    public function test_concurrent_append_from_another_process_waits_for_the_lock_and_then_fails_with_concurrency_exception(): void
    {
        $dsn = $this->postgresDsn();

        $this->withPostgresStore(function (EventStore $store, $_connection, string $prefix) use ($dsn): void {
            $query = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-1'),
            )->ofTypes('student.subscribed');
            $event = new Event(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            );

            $workerA = null;
            $workerB = null;

            try {
                $store->append([$event]);
                $staleVersion = $store->load($query)->queryVersion;

                $workerA = self::startConcurrencyWorker([
                    'dsn' => $dsn,
                    'tablePrefix' => $prefix,
                    'mode' => 'hold_then_commit',
                    ...self::queryVersionToWorkerConfig($staleVersion, $query),
                    'event' => [
                        'eventType' => $event->eventType,
                        'payload' => $event->payload,
                        'domainIds' => $event->domainIds,
                    ],
                ], true);

                $workerA->waitForEvent('ready', 5_000);

                $workerB = self::startConcurrencyWorker([
                    'dsn' => $dsn,
                    'tablePrefix' => $prefix,
                    'mode' => 'append_once',
                    ...self::queryVersionToWorkerConfig($staleVersion, $query),
                    'event' => [
                        'eventType' => $event->eventType,
                        'payload' => $event->payload,
                        'domainIds' => $event->domainIds,
                    ],
                    'lockTimeoutMs' => 5_000,
                ]);

                $workerB->waitForEvent('attempting', 5_000);
                usleep(250_000);

                self::assertTrue($workerB->isRunning());

                $workerA->send('release', true);

                $workerAResult = $workerA->waitForResult(5_000);
                $workerA = null;
                $workerBResult = $workerB->waitForResult(5_000);
                $workerB = null;

                self::assertSame(0, $workerAResult['exitCode']);
                self::assertSame('ok', $workerAResult['status']);
                self::assertSame(10, $workerBResult['exitCode'], $workerBResult['stderr']);
                self::assertSame('concurrency_exception', $workerBResult['status']);
                self::assertCount(2, $store->load($query)->events);
            } finally {
                $workerA?->stop();
                $workerB?->stop();
            }
        });
    }

    public function test_concurrent_append_for_the_same_course_and_another_student_waits_and_then_succeeds(): void
    {
        $dsn = $this->postgresDsn();

        $this->withPostgresStore(function (EventStore $store, $_connection, string $prefix) use ($dsn): void {
            $studentOneQuery = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-1'),
            )->ofTypes('student.subscribed');
            $studentTwoQuery = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-2'),
            )->ofTypes('student.subscribed');

            $studentOneEvent = new Event(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            );
            $studentTwoEvent = new Event(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-2'],
            );

            $workerA = null;
            $workerB = null;

            try {
                $store->append([$studentOneEvent]);
                $staleVersion = $store->load($studentOneQuery)->queryVersion;

                $workerA = self::startConcurrencyWorker([
                    'dsn' => $dsn,
                    'tablePrefix' => $prefix,
                    'mode' => 'hold_then_commit',
                    ...self::queryVersionToWorkerConfig($staleVersion, $studentOneQuery),
                    'event' => [
                        'eventType' => $studentOneEvent->eventType,
                        'payload' => $studentOneEvent->payload,
                        'domainIds' => $studentOneEvent->domainIds,
                    ],
                ], true);

                $workerA->waitForEvent('ready', 5_000);

                $workerB = self::startConcurrencyWorker([
                    'dsn' => $dsn,
                    'tablePrefix' => $prefix,
                    'mode' => 'append_once',
                    'event' => [
                        'eventType' => $studentTwoEvent->eventType,
                        'payload' => $studentTwoEvent->payload,
                        'domainIds' => $studentTwoEvent->domainIds,
                    ],
                    'lockTimeoutMs' => 5_000,
                ]);

                $workerB->waitForEvent('attempting', 5_000);
                $workerA->send('release', true);

                $workerAResult = $workerA->waitForResult(5_000);
                $workerA = null;
                $workerBResult = $workerB->waitForResult(5_000);
                $workerB = null;

                self::assertSame(0, $workerAResult['exitCode']);
                self::assertSame('ok', $workerAResult['status']);
                self::assertSame(0, $workerBResult['exitCode'], $workerBResult['stderr']);
                self::assertSame('ok', $workerBResult['status']);
                self::assertCount(2, $store->load($studentOneQuery)->events);
                self::assertCount(1, $store->load($studentTwoQuery)->events);
            } finally {
                $workerA?->stop();
                $workerB?->stop();
            }
        });
    }

    #[DataProvider('implementationProvider')]
    public function test_implementation_rejects_a_stale_multi_branch_query_version_when_a_lower_event_id_becomes_visible_later(
        string $implementation,
    ): void {
        $dsn = $this->postgresDsn();

        $this->withPostgresStore(function (EventStore $store, $_connection, string $prefix) use ($dsn, $implementation): void {
            $courseQuery = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
            )->ofTypes('course.created');
            $studentQuery = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-2'),
            )->ofTypes('student.subscribed');
            $combinedQuery = StreamQuery::anyOf($courseQuery, $studentQuery);

            $courseCreatedEvent = new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: ['courseId' => 'course-123'],
            );
            $studentSubscribedEvent = new Event(
                eventType: 'student.subscribed',
                payload: ['slot' => 2],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-2'],
            );
            $followUpEvent = new Event(
                eventType: 'student.subscribed',
                payload: ['slot' => 3],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-2'],
            );

            $workerA = null;
            $workerB = null;

            try {
                $workerA = self::startConcurrencyWorker([
                    'dsn' => $dsn,
                    'tablePrefix' => $prefix,
                    'implementation' => $implementation,
                    'mode' => 'hold_then_commit',
                    'event' => [
                        'eventType' => $courseCreatedEvent->eventType,
                        'payload' => $courseCreatedEvent->payload,
                        'domainIds' => $courseCreatedEvent->domainIds,
                    ],
                ], true);

                $workerA->waitForEvent('ready', 5_000);

                $workerB = self::startConcurrencyWorker([
                    'dsn' => $dsn,
                    'tablePrefix' => $prefix,
                    'implementation' => $implementation,
                    'mode' => 'append_once',
                    'event' => [
                        'eventType' => $studentSubscribedEvent->eventType,
                        'payload' => $studentSubscribedEvent->payload,
                        'domainIds' => $studentSubscribedEvent->domainIds,
                    ],
                    'lockTimeoutMs' => 5_000,
                ]);

                $workerBResult = $workerB->waitForResult(5_000);
                $workerB = null;

                self::assertSame(0, $workerBResult['exitCode'], $workerBResult['stderr']);
                self::assertSame('ok', $workerBResult['status']);

                $staleVersion = $store->load($combinedQuery)->queryVersion;
                self::assertSame(1, $staleVersion->version);

                $workerA->send('release', true);
                $workerAResult = $workerA->waitForResult(5_000);
                $workerA = null;

                self::assertSame(0, $workerAResult['exitCode']);
                self::assertSame('ok', $workerAResult['status']);

                try {
                    $store->append([$followUpEvent], $staleVersion);
                    self::fail('Expected ConcurrencyException to be thrown.');
                } catch (ConcurrencyException) {
                }

                $result = $store->load($combinedQuery);
                self::assertCount(2, $result->events);
                self::assertSame(2, $result->queryVersion->version);
            } finally {
                $workerA?->stop();
                $workerB?->stop();
            }
        }, $implementation);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function implementationProvider(): iterable
    {
        foreach (postgresTestSupportedImplementations() as $implementation) {
            yield $implementation => [$implementation];
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function startConcurrencyWorker(array $config, bool $interactive = false): ConcurrencyWorker
    {
        $script = __DIR__ . '/Fixtures/postgres_event_store_concurrency_worker.php';
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

        return new ConcurrencyWorker($process, $input);
    }

    /**
     * @return array<string, mixed>
     */
    private static function queryVersionToWorkerConfig(QueryVersion $queryVersion, StreamQuery $query): array
    {
        return [
            'query' => self::streamQueryToArray($query),
            'version' => $queryVersion->version,
        ];
    }

    /**
     * @return array{
     *     domainIdentifiers: array<string, string>,
     *     eventNames: list<string>,
     *     orCriteria: list<array<string, string>>,
     *     orBranchEventNames: null|list<list<string>>
     * }
     */
    private static function streamQueryToArray(StreamQuery $query): array
    {
        return [
            'domainIdentifiers' => $query->domainIdentifiers,
            'eventNames' => array_values($query->eventNames),
            'orCriteria' => array_values($query->orCriteria),
            'orBranchEventNames' => null === $query->orBranchEventNames
                ? null
                : array_map(static fn(array $types): array => array_values($types), $query->orBranchEventNames),
        ];
    }
}
