<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Tests\Support\QueueTestCase;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class QueueReplacementTest extends QueueTestCase
{
    #[DataProvider('backends')]
    public function test_replacement_keeps_only_the_latest_message_for_the_same_key(string $backend): void
    {
        $f = $this->fixture($backend);
        $f->queue->enqueue($this->envelope('old'), new AsyncEnqueueOptions(key: 'refresh', replace: true));
        $id = $f->queue->enqueue($this->envelope('new'), new AsyncEnqueueOptions(key: 'refresh', replace: true));
        self::assertSame(1, $f->count());
        $delivery = $f->queue->reserve();
        self::assertNotNull($delivery);
        self::assertSame($id->value, $delivery->messageId->value);
        self::assertSame('new', $delivery->envelope->message->value);
        $f->queue->acknowledge($delivery);
        self::assertNull($f->queue->reserve());
    }
}
