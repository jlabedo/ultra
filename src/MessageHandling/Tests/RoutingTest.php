<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Exception\NoHandlerFound;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\EmptyMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\FarewellMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\SelfGreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_Compiler')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class RoutingTest extends MessageHandlingCompilerTestCase
{
    public function test_it_dispatches_registered_messages_and_returns_handler_results(): void
    {
        $mediator = $this
            ->ultraLight()
            ->withService(new class {
                #[Handles]
                public function greet(GreetingMessage $message): string
                {
                    return 'Hello, ' . $message->name . '!';
                }

                #[Handles]
                public function farewell(FarewellMessage $message): string
                {
                    return 'Goodbye, ' . $message->name . '!';
                }
            })
            ->boot()
            ->mediator();

        static::assertSame('Hello, Alice!', $mediator->send(new GreetingMessage('Alice')));
        static::assertSame('Goodbye, Bob!', $mediator->send(new FarewellMessage('Bob')));
    }

    public function test_it_throws_when_no_handler_is_registered_for_the_message(): void
    {
        $mediator = $this
            ->ultraLight()
            ->withService(new class {
                #[Handles]
                public function greet(GreetingMessage $message): string
                {
                    return 'Hello, ' . $message->name . '!';
                }
            })
            ->boot()
            ->mediator();

        $this->expectException(NoHandlerFound::class);

        $mediator->send(new EmptyMessage());
    }

    public function test_it_supports_self_handlers_and_regular_handlers_in_the_same_router(): void
    {
        $mediator = $this
            ->ultraLight()
            ->withClass(SelfGreetingMessage::class)
            ->withService(new class {
                #[Handles]
                public function greet(GreetingMessage $message): string
                {
                    return 'Handler hello, ' . $message->name . '!';
                }
            })
            ->boot()
            ->mediator();

        static::assertSame('Handler hello, Alice!', $mediator->send(new GreetingMessage('Alice')));
        static::assertSame('Hello, Carol!', $mediator->send(new SelfGreetingMessage('Carol')));
    }

    public function test_event_publication_matches_interface_subscriptions_in_priority_order(): void
    {
        $listener = new class {
            /** @var list<string> */
            public array $entries = [];

            #[EventHandler(priority: 10)]
            public function zConcrete(PriorityEvent $event): void
            {
                $this->entries[] = 'zConcrete:' . $event->payload;
            }

            #[EventHandler(priority: 10)]
            public function aConcrete(PriorityEvent $event): void
            {
                $this->entries[] = 'aConcrete:' . $event->payload;
            }

            #[EventHandler(priority: 20)]
            public function generic(RoutedEvent $event): void
            {
                $this->entries[] = 'interface:' . $event->payload;
            }
        };
        $bus = $this
            ->ultraLight()
            ->withEventClass(PriorityEvent::class, EmptyMessage::class)
            ->withService($listener, 'z-listener')
            ->withService(new class($listener) {
                public function __construct(
                    private object $listener,
                ) {}

                #[EventHandler(priority: 10)]
                public function external(PriorityEvent $event): void
                {
                    $this->listener->entries[] = 'external:' . $event->payload;
                }
            }, 'a-listener')
            ->boot()
            ->eventBus();
        $bus->publish(new PriorityEvent('stored'));
        $bus->publish(new EmptyMessage());
        static::assertSame(
            ['interface:stored', 'external:stored', 'aConcrete:stored', 'zConcrete:stored'],
            $listener->entries,
        );
        $this->expectException(\Ultra\MessageHandling\Exception\UnknownEvent::class);
        $bus->publish(new GreetingMessage('unknown'));
    }
}

interface RoutedEvent {}

final readonly class PriorityEvent implements RoutedEvent
{
    public function __construct(
        public string $payload,
    ) {}
}
