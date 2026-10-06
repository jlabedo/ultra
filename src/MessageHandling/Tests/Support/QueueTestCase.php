<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncHeaderNames;
use Ultra\MessageHandling\Async\AsyncHeaders;
use Ultra\MessageHandling\MessageContext;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Time\StatickClock;

abstract class QueueTestCase extends TestCase
{
    /** @var list<QueueFixture> */
    private array $fixtures = [];

    /** @return iterable<string, array{string}> */
    public static function backends(): iterable
    {
        yield 'memory' => ['memory'];
        yield 'Postgres' => ['postgres'];
    }

    protected function fixture(string $backend = 'memory'): QueueFixture
    {
        $connection = null;
        if ('postgres' === $backend) {
            $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
            if (false === $dsn || '' === $dsn) {
                self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run the Postgres queue guarantees.');
            }
            $connection = PostgresConnectionSupport::sharedConnection($dsn);
        }
        $fixture = new QueueFixture(new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00')), $connection);
        $this->fixtures[] = $fixture;
        return $fixture;
    }

    protected function envelope(string $value): AsyncEnvelope
    {
        return new AsyncEnvelope(
            new QueueMessage($value),
            MessageContext::fromArray(['traceId' => 'trace-1'])->withLocal('runtime', new \stdClass()),
            AsyncHeaders::empty()->with(AsyncHeaderNames::ROUTE, 'test.route'),
        );
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->fixtures) as $fixture) $fixture->close();
        } finally {
            $this->fixtures = [];
            parent::tearDown();
        }
    }
}
