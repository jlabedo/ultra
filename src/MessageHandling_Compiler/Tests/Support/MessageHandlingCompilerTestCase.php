<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\MessageHandling\MessageContextKeys;

require_once dirname(__DIR__).'/Fixtures/Messages.php';

abstract class MessageHandlingCompilerTestCase extends TestCase
{
    /**
     * @return UltraLight<UltraLightContainer>
     */
    protected function ultraLight(): UltraLight
    {
        return new UltraLight();
    }

    /**
     * @param list<array{message: class-string, context: array<array-key, mixed>}> $entries
     */
    protected function assertMessageFlowContexts(
        array $entries,
        string $firstMessage,
        string $secondMessage,
        string $traceId,
    ): void {
        self::assertCount(2, $entries);
        self::assertSame($firstMessage, $entries[0]['message']);
        self::assertSame($secondMessage, $entries[1]['message']);
        self::assertSame($traceId, $entries[0]['context']['traceId']);
        self::assertSame($traceId, $entries[1]['context']['traceId']);
        self::assertArrayHasKey(MessageContextKeys::MESSAGE_ID, $entries[0]['context']);
        self::assertArrayHasKey(MessageContextKeys::CORRELATION_ID, $entries[0]['context']);
        self::assertArrayHasKey(MessageContextKeys::MESSAGE_ID, $entries[1]['context']);
        self::assertArrayHasKey(MessageContextKeys::CORRELATION_ID, $entries[1]['context']);
        self::assertArrayHasKey(MessageContextKeys::CAUSATION_ID, $entries[1]['context']);
        self::assertSame(
            $entries[0]['context'][MessageContextKeys::MESSAGE_ID],
            $entries[0]['context'][MessageContextKeys::CORRELATION_ID],
        );
        self::assertSame(
            $entries[0]['context'][MessageContextKeys::CORRELATION_ID],
            $entries[1]['context'][MessageContextKeys::CORRELATION_ID],
        );
        self::assertSame(
            $entries[0]['context'][MessageContextKeys::MESSAGE_ID],
            $entries[1]['context'][MessageContextKeys::CAUSATION_ID],
        );
    }
}
