<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextKeys;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\FarewellMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\RecordedEvent;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

final class FixedMessageContextPropagator implements MessageContextPropagatorInterface
{
    public function createDispatchContext(MessageContext $context): MessageContext
    {
        if ($context->has(MessageContextKeys::MESSAGE_ID) && ! $context->has(MessageContextKeys::CAUSATION_ID)) {
            return $context
                ->with(MessageContextKeys::MESSAGE_ID, 'fixed-child')
                ->with(MessageContextKeys::CORRELATION_ID, 'fixed-correlation')
                ->with(MessageContextKeys::CAUSATION_ID, 'fixed-root')
            ;
        }

        return $context
            ->with(MessageContextKeys::MESSAGE_ID, 'fixed-root')
            ->with(MessageContextKeys::CORRELATION_ID, 'fixed-correlation')
        ;
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

final class MediatorContextPropagationTest extends MessageHandlingCompilerTestCase
{
    public function test_it_propagates_context_through_nested_mediator_sends(): void
    {
        $contexts = new RecordedMessageContexts();

        $mediator = $this->ultraLight()
            ->withService($contexts)
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message, MediatorInterface $mediator): string
                {
                    return $mediator->send(new FarewellMessage($message->name));
                }
            })
            ->withService(new class() {
                #[Handles]
                public function handle(FarewellMessage $message): string
                {
                    return 'child: ' . $message->name;
                }
            })
            ->withService(new class($contexts) {
                public function __construct(private readonly RecordedMessageContexts $contexts) {}

                #[Before(static function (\ReflectionMethod $method): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function record(object $message, MessageContext $context): void
                {
                    $this->contexts->entries[] = [
                        'message' => $message::class,
                        'context' => $context->all(),
                    ];
                }
            })
            ->mediator()
        ;

        self::assertSame(
            'child: nested',
            $mediator->send(new GreetingMessage('nested'), ['traceId' => 'trace-456']),
        );

        $this->assertMessageFlowContexts(
            $contexts->entries,
            GreetingMessage::class,
            FarewellMessage::class,
            'trace-456',
        );
    }

    public function test_it_accepts_message_context_instances_at_the_send_boundary(): void
    {
        $contexts = new RecordedMessageContexts();

        $mediator = $this->ultraLight()
            ->withService($contexts)
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message, MediatorInterface $mediator): string
                {
                    return $mediator->send(new FarewellMessage($message->name));
                }
            })
            ->withService(new class() {
                #[Handles]
                public function handle(FarewellMessage $message): string
                {
                    return 'child: ' . $message->name;
                }
            })
            ->withService(new class($contexts) {
                public function __construct(private readonly RecordedMessageContexts $contexts) {}

                #[Before(static function (\ReflectionMethod $method): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function record(object $message, MessageContext $context): void
                {
                    $this->contexts->entries[] = [
                        'message' => $message::class,
                        'context' => $context->all(),
                    ];
                }
            })
            ->mediator()
        ;

        self::assertSame(
            'child: typed',
            $mediator->send(
                new GreetingMessage('typed'),
                MessageContext::coerce(['traceId' => 'trace-typed']),
            ),
        );

        $this->assertMessageFlowContexts(
            $contexts->entries,
            GreetingMessage::class,
            FarewellMessage::class,
            'trace-typed',
        );
    }

    public function test_it_uses_a_configured_message_context_propagator_service(): void
    {
        $contexts = new RecordedMessageContexts();

        $mediator = $this->ultraLight()
            ->withService(new FixedMessageContextPropagator(), MessageContextPropagatorInterface::class)
            ->withService($contexts)
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message, MediatorInterface $mediator): string
                {
                    return $mediator->send(new FarewellMessage($message->name));
                }
            })
            ->withService(new class() {
                #[Handles]
                public function handle(FarewellMessage $message): string
                {
                    return 'child: ' . $message->name;
                }
            })
            ->withService(new class($contexts) {
                public function __construct(private readonly RecordedMessageContexts $contexts) {}

                #[Before(static function (\ReflectionMethod $method): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function record(object $message, MessageContext $context): void
                {
                    $this->contexts->entries[] = [
                        'message' => $message::class,
                        'context' => $context->all(),
                    ];
                }
            })
            ->mediator()
        ;

        self::assertSame(
            'child: custom',
            $mediator->send(new GreetingMessage('custom'), ['traceId' => 'trace-custom']),
        );
        self::assertSame('fixed-root', $contexts->entries[0]['context'][MessageContextKeys::MESSAGE_ID]);
        self::assertSame(
            'fixed-correlation',
            $contexts->entries[0]['context'][MessageContextKeys::CORRELATION_ID],
        );
        self::assertSame('fixed-child', $contexts->entries[1]['context'][MessageContextKeys::MESSAGE_ID]);
        self::assertSame(
            'fixed-correlation',
            $contexts->entries[1]['context'][MessageContextKeys::CORRELATION_ID],
        );
        self::assertSame('fixed-root', $contexts->entries[1]['context'][MessageContextKeys::CAUSATION_ID]);
    }

    public function test_it_propagates_dispatch_context_to_an_injected_event_bus(): void
    {
        $eventBus = new RecordingEventBus();

        $mediator = $this->ultraLight()
            ->withService($eventBus, EventBusInterface::class)
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message, EventBusInterface $eventBus): string
                {
                    $eventBus->publish(new RecordedEvent($message->name));

                    return 'published: ' . $message->name;
                }
            })
            ->mediator()
        ;

        self::assertSame(
            'published: bridge',
            $mediator->send(new GreetingMessage('bridge'), ['traceId' => 'trace-eb']),
        );
        self::assertCount(1, $eventBus->calls);
        self::assertInstanceOf(RecordedEvent::class, $eventBus->calls[0]['event']);
        self::assertSame('trace-eb', $eventBus->calls[0]['context']['traceId']);
        self::assertArrayHasKey(MessageContextKeys::MESSAGE_ID, $eventBus->calls[0]['context']);
        self::assertArrayHasKey(MessageContextKeys::CORRELATION_ID, $eventBus->calls[0]['context']);
    }
}
