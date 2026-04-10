<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AsyncMessageContextTest extends MessageHandlingCompilerTestCase
{
    public function test_message_context_is_preserved_through_enqueue_dequeue(): void
    {
        $capturedContext = null;

        $container = $this->ultraLight()
            ->withService(new class ($capturedContext) {
                public ?MessageContext $captured;

                public function __construct(?MessageContext &$captured)
                {
                    $this->captured = &$captured;
                }

                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function captureContext(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->captured = $context;

                    return $proceed();
                }
            })
            ->withService(new class () {
                #[Handles]
                #[Asynchronous]
                public function handle(GreetingMessage $message): string
                {
                    return 'handled';
                }
            })
            ->boot()
        ;

        $container->mediator()->send(new GreetingMessage('context-test'), [
            'traceId' => 'my-trace-123',
        ]);

        // Context was not yet captured (advice runs at dequeue time)
        self::assertNull($capturedContext);

        $container->queueWorker()->drain();

        // Now the context should be captured with the original values preserved
        self::assertNotNull($capturedContext);
        self::assertArrayHasKey(MessageContextKeys::MESSAGE_ID, $capturedContext->all());
        self::assertArrayHasKey(MessageContextKeys::CORRELATION_ID, $capturedContext->all());
        self::assertSame('my-trace-123', $capturedContext->get('traceId'));
    }
}
