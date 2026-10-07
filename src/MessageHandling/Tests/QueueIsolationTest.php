<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Tests\Support\QueueTestCase;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class QueueIsolationTest extends QueueTestCase
{
    public function test_queue_names_isolate_reservation_replacement_and_acknowledgement(): void
    {
        $f = $this->fixture('postgres');
        $other = $f->peer($f->connection, 'other');
        $f->queue->enqueue($this->envelope('default'), new AsyncEnqueueOptions(key: 'refresh', replace: true));
        $id = $other->enqueue($this->envelope('other'), new AsyncEnqueueOptions(key: 'refresh', replace: true));
        static::assertSame(2, $f->count());
        $delivery = $other->reserve();
        static::assertNotNull($delivery);
        static::assertSame($id->value, $delivery->messageId->value);
        $f->queue->acknowledge($delivery);
        static::assertSame(2, $f->count());
        $other->acknowledge($delivery);
        $default = $f->queue->reserve();
        static::assertNotNull($default);
        static::assertSame('default', $default->envelope->message->value);
    }
}
