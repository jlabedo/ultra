<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\MessageContextPropagator;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\FarewellMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\RecordedEvent;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

final class FixedMessageContextPropagator implements MessageContextPropagatorInterface
{
    public function normalizeContext(MessageContext $context): MessageContext
    {
        if ($context->has(MessageContextKeys::MESSAGE_ID)) {
            return $context;
        }

        return $context->with(MessageContextKeys::MESSAGE_ID, 'fixed-root')->with(
            MessageContextKeys::CORRELATION_ID,
            'fixed-correlation',
        );
    }

    public function createChildContext(MessageContext $parentContext): MessageContext
    {
        return $parentContext
            ->with(MessageContextKeys::MESSAGE_ID, 'fixed-child')
            ->with(MessageContextKeys::CORRELATION_ID, 'fixed-correlation')
            ->with(MessageContextKeys::CAUSATION_ID, 'fixed-root');
    }
}

final class RecordingEventBus implements EventBusInterface
{
    /** @var list<array{event: object, context: array<array-key, mixed>}> */
    public array $calls = [];

    public function publish(object $event, array|MessageContext $context = []): void
    {
        $this->calls[] = [
            'event' => $event,
            'context' => MessageContext::coerce($context)->all(),
        ];
    }
}

final class RecordedMessageContexts
{
    /** @var list<array{message: class-string, context: array<array-key, mixed>}> */
    public array $entries = [];
}

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_Compiler')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class ContextPropagationTest extends MessageHandlingCompilerTestCase
{
    #[DataProvider('boundaryContexts')]
    public function test_it_propagates_context_through_nested_mediator_sends(bool $typed): void
    {
        $contexts = new RecordedMessageContexts();

        $mediator = $this
            ->ultraLight()
            ->withService($contexts)
            ->withClass(ForwardingHandler::class, ChildHandler::class)
            ->withService(new ContextRecorder($contexts))
            ->boot()
            ->mediator();

        static::assertSame('child: nested', $mediator->send(
            new GreetingMessage('nested'),
            $typed ? MessageContext::coerce(['traceId' => 'trace-456']) : ['traceId' => 'trace-456'],
        ));

        $this->assertMessageFlowContexts(
            $contexts->entries,
            GreetingMessage::class,
            FarewellMessage::class,
            'trace-456',
        );
    }

    public function test_it_uses_a_configured_message_context_propagator_service(): void
    {
        $contexts = new RecordedMessageContexts();

        $mediator = $this
            ->ultraLight()
            ->withService(new FixedMessageContextPropagator(), MessageContextPropagatorInterface::class)
            ->withService($contexts)
            ->withClass(ForwardingHandler::class, ChildHandler::class)
            ->withService(new ContextRecorder($contexts))
            ->boot()
            ->mediator();

        static::assertSame('child: custom', $mediator->send(new GreetingMessage('custom'), [
            'traceId' => 'trace-custom',
        ]));
        static::assertSame('fixed-root', $contexts->entries[0]['context'][MessageContextKeys::MESSAGE_ID]);
        static::assertSame('fixed-correlation', $contexts->entries[0]['context'][MessageContextKeys::CORRELATION_ID]);
        static::assertSame('fixed-child', $contexts->entries[1]['context'][MessageContextKeys::MESSAGE_ID]);
        static::assertSame('fixed-correlation', $contexts->entries[1]['context'][MessageContextKeys::CORRELATION_ID]);
        static::assertSame('fixed-root', $contexts->entries[1]['context'][MessageContextKeys::CAUSATION_ID]);
    }

    public function test_it_propagates_dispatch_context_to_an_injected_event_bus(): void
    {
        $eventBus = new RecordingEventBus();

        $mediator = $this
            ->ultraLight()
            ->withService($eventBus, EventBusInterface::class)
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message, EventBusInterface $eventBus): string
                {
                    $eventBus->publish(new RecordedEvent($message->name));

                    return 'published: ' . $message->name;
                }
            })
            ->boot()
            ->mediator();

        static::assertSame('published: bridge', $mediator->send(new GreetingMessage('bridge'), [
            'traceId' => 'trace-eb',
        ]));
        static::assertCount(1, $eventBus->calls);
        static::assertInstanceOf(RecordedEvent::class, $eventBus->calls[0]['event']);
        static::assertSame('trace-eb', $eventBus->calls[0]['context']['traceId']);
        static::assertArrayHasKey(MessageContextKeys::MESSAGE_ID, $eventBus->calls[0]['context']);
        static::assertArrayHasKey(MessageContextKeys::CORRELATION_ID, $eventBus->calls[0]['context']);
    }

    public function test_it_creates_a_new_message_id_for_deeply_nested_event_bus_publish_calls(): void
    {
        $contexts = new RecordedMessageContexts();

        $eventBus = $this
            ->ultraLight()
            ->withEventClass(RecordedEvent::class)
            ->withService($contexts)
            ->withService(new class {
                #[EventHandler]
                public function handle(RecordedEvent $event, EventBusInterface $eventBus): void
                {
                    if ('root' === $event->payload || 'child' === $event->payload) {
                        $eventBus->publish(new RecordedEvent('root' === $event->payload ? 'child' : 'grandchild'));
                    }
                }
            })
            ->withService(new ContextRecorder($contexts))
            ->boot()
            ->eventBus();

        $eventBus->publish(new RecordedEvent('root'), ['traceId' => 'trace-nested-event']);

        static::assertCount(3, $contexts->entries);

        $rootContext = $contexts->entries[0]['context'];
        $childContext = $contexts->entries[1]['context'];
        $grandchildContext = $contexts->entries[2]['context'];

        static::assertSame('trace-nested-event', $rootContext['traceId']);
        static::assertSame('trace-nested-event', $childContext['traceId']);
        static::assertSame('trace-nested-event', $grandchildContext['traceId']);
        static::assertNotSame(
            $rootContext[MessageContextKeys::MESSAGE_ID],
            $childContext[MessageContextKeys::MESSAGE_ID],
        );
        static::assertSame(
            $rootContext[MessageContextKeys::CORRELATION_ID],
            $childContext[MessageContextKeys::CORRELATION_ID],
        );
        static::assertSame(
            $rootContext[MessageContextKeys::MESSAGE_ID],
            $childContext[MessageContextKeys::CAUSATION_ID],
        );
        static::assertNotSame(
            $childContext[MessageContextKeys::MESSAGE_ID],
            $grandchildContext[MessageContextKeys::MESSAGE_ID],
        );
        static::assertSame(
            $rootContext[MessageContextKeys::CORRELATION_ID],
            $grandchildContext[MessageContextKeys::CORRELATION_ID],
        );
        static::assertSame(
            $childContext[MessageContextKeys::MESSAGE_ID],
            $grandchildContext[MessageContextKeys::CAUSATION_ID],
        );
    }

    public function test_message_context_is_preserved_through_enqueue_and_delivery(): void
    {
        $capturedContext = null;

        $container = $this
            ->ultraLight()
            ->withService(new class($capturedContext) {
                public ?MessageContext $captured;

                public function __construct(?MessageContext &$captured)
                {
                    $this->captured = &$captured;
                }

                #[Around]
                public function captureContext(
                    callable $proceed,
                    object $message,
                    MessageContext $context,
                ): ProceedResult {
                    $this->captured = $context;

                    return $proceed();
                }
            })
            ->withService(new class {
                #[Handles]
                #[Asynchronous]
                public function handle(GreetingMessage $message): string
                {
                    return 'handled';
                }
            })
            ->boot();

        $container->mediator()->send(new GreetingMessage('context-test'), [
            'traceId' => 'my-trace-123',
        ]);

        // Context was not yet captured (advice runs at dequeue time)
        static::assertNull($capturedContext);

        $container->queueWorker()->drain();

        // Now the context should be captured with the original values preserved
        static::assertNotNull($capturedContext);
        static::assertArrayHasKey(MessageContextKeys::MESSAGE_ID, $capturedContext->all());
        static::assertArrayHasKey(MessageContextKeys::CORRELATION_ID, $capturedContext->all());
        static::assertSame('my-trace-123', $capturedContext->get('traceId'));
    }

    /**
     * @param list<array{message: class-string, context: array<array-key, mixed>}> $entries
     */
    private function assertMessageFlowContexts(
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

    /** @return iterable<string, array{bool}> */
    public static function boundaryContexts(): iterable
    {
        yield 'array' => [false];
        yield 'MessageContext' => [true];
    }

    public function test_it_normalizes_a_context_without_ids(): void
    {
        $propagator = new MessageContextPropagator();

        $context = $propagator->normalizeContext(MessageContext::empty());

        static::assertNotSame('', $context->get(MessageContextKeys::MESSAGE_ID));
        static::assertSame(
            $context->get(MessageContextKeys::MESSAGE_ID),
            $context->get(MessageContextKeys::CORRELATION_ID),
        );
        static::assertFalse($context->has(MessageContextKeys::CAUSATION_ID));
    }

    public function test_it_preserves_existing_ids_when_normalizing_a_context(): void
    {
        $propagator = new MessageContextPropagator();

        $context = MessageContext::fromArray([
            MessageContextKeys::MESSAGE_ID => 'message-1',
            MessageContextKeys::CORRELATION_ID => 'correlation-1',
            MessageContextKeys::CAUSATION_ID => 'causation-1',
        ]);

        static::assertSame($context->all(), $propagator->normalizeContext($context)->all());
    }

    public function test_child_contexts_keep_correlation_and_record_the_immediate_parent(): void
    {
        $propagator = new MessageContextPropagator();
        $root = $propagator->normalizeContext(MessageContext::empty());
        $parent = $root;
        for ($depth = 0; $depth < 2; ++$depth) {
            $child = $propagator->createChildContext($parent);
            static::assertNotSame(
                $parent->get(MessageContextKeys::MESSAGE_ID),
                $child->get(MessageContextKeys::MESSAGE_ID),
            );
            static::assertSame(
                $root->get(MessageContextKeys::CORRELATION_ID),
                $child->get(MessageContextKeys::CORRELATION_ID),
            );
            static::assertSame(
                $parent->get(MessageContextKeys::MESSAGE_ID),
                $child->get(MessageContextKeys::CAUSATION_ID),
            );
            $parent = $child;
        }
    }
}

final readonly class ContextRecorder
{
    public function __construct(
        private RecordedMessageContexts $contexts,
    ) {}

    #[Before]
    public function record(object $message, MessageContext $context): void
    {
        $this->contexts->entries[] = ['message' => $message::class, 'context' => $context->all()];
    }
}

final class ForwardingHandler
{
    #[Handles]
    public function handle(GreetingMessage $message, MediatorInterface $mediator): string
    {
        return $mediator->send(new FarewellMessage($message->name));
    }
}

final class ChildHandler
{
    #[Handles]
    public function handle(FarewellMessage $message): string
    {
        return 'child: ' . $message->name;
    }
}
