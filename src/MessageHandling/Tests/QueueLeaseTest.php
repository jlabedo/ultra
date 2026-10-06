<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MessageHandling\Async\AsyncDelivery;
use Ultra\MessageHandling\Tests\Support\QueueTestCase;
use Ultra\Testing\PostgresTestSupport;
use Ultra\Time\Duration;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class QueueLeaseTest extends QueueTestCase
{
    #[DataProvider('backends')]
    public function test_reservation_holds_the_message_until_its_owner_acknowledges(string $backend): void
    {
        $f = $this->fixture($backend);
        $id = $f->queue->enqueue($this->envelope('first'));
        $delivery = $f->queue->reserve(Duration::seconds(30));

        self::assertInstanceOf(AsyncDelivery::class, $delivery);
        self::assertSame($id->value, $delivery->messageId->value);
        self::assertSame(1, $delivery->attempts);
        self::assertNull($f->queue->reserve());
        self::assertSame(1, $f->count());

        $f->queue->acknowledge($delivery);
        self::assertSame(0, $f->count());
        self::assertNull($f->queue->reserve());
    }

    #[DataProvider('backends')]
    public function test_expiry_redelivers_with_a_new_lease_and_ignores_stale_acknowledgement_and_release(string $backend): void
    {
        $f = $this->fixture($backend);
        $id = $f->queue->enqueue($this->envelope('first'));
        $stale = $f->queue->reserve(Duration::seconds(10));
        self::assertInstanceOf(AsyncDelivery::class, $stale);
        $f->clock->sleep(Duration::seconds(11));
        $current = $f->queue->reserve(Duration::seconds(10));
        self::assertInstanceOf(AsyncDelivery::class, $current);
        self::assertSame($id->value, $current->messageId->value);
        self::assertNotSame($stale->leaseId, $current->leaseId);
        self::assertSame(2, $current->attempts);

        $f->queue->acknowledge($stale);
        self::assertSame(1, $f->count());
        $f->queue->release($stale);
        self::assertNull($f->queue->reserve());
        $f->queue->acknowledge($current);
        self::assertSame(0, $f->count());
    }

    #[DataProvider('backends')]
    public function test_only_the_current_owner_can_extend_a_lease(string $backend): void
    {
        $f = $this->fixture($backend);
        $f->queue->enqueue($this->envelope('first'));
        $stale = $f->queue->reserve(Duration::seconds(10));
        self::assertNotNull($stale);
        $f->clock->sleep(Duration::seconds(11));
        $current = $f->queue->reserve(Duration::seconds(10));
        self::assertNotNull($current);
        $f->queue->renew($stale, Duration::seconds(100));
        $f->clock->sleep(Duration::seconds(11));
        $renewed = $f->queue->reserve(Duration::seconds(10));
        self::assertNotNull($renewed);
        $f->queue->renew($renewed, Duration::seconds(30));
        $f->clock->sleep(Duration::seconds(11));
        self::assertNull($f->queue->reserve());
        $f->clock->sleep(Duration::seconds(20));
        self::assertNotNull($f->queue->reserve());
    }

    public function test_workers_skip_locked_messages_and_reserve_distinct_deliveries(): void
    {
        $f = $this->fixture('postgres');
        $f->queue->enqueue($this->envelope('first'));
        $f->queue->enqueue($this->envelope('second'));
        $other = PostgresTestSupport::connection(getenv('ULTRA_TEST_POSTGRES_DSN'));
        try {
            $other->executeStatement("SET lock_timeout = '200ms'");
            $f->connection->beginTransaction();
            $first = $f->queue->reserve();
            self::assertNotNull($first);
            $second = $f->peer($other)->reserve();
            self::assertNotNull($second);
            self::assertNotSame($first->messageId->value, $second->messageId->value);
            $f->connection->commit();
            self::assertNull($f->queue->reserve());
        } finally {
            $other->close();
        }
    }

}
