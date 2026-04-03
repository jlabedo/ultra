<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\RecordedEvent;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

final class MessageHandlingBundleTest extends MessageHandlingCompilerTestCase
{
    public function test_it_autoconfigures_anonymous_handler_services(): void
    {
        $mediator = $this->ultraLight()
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'Hello, ' . $message->name . '!';
                }
            })
            ->mediator()
        ;

        self::assertSame('Hello, Alice!', $mediator->send(new GreetingMessage('Alice')));
    }

    public function test_it_autoconfigures_event_handler_services(): void
    {
        $recorded = [];

        $eventBus = $this->ultraLight()
            ->withEventClass(RecordedEvent::class)
            ->withService(new class($recorded) {
                /** @var list<RecordedEvent> */
                public array $recorded;

                /**
                 * @param list<RecordedEvent> $recorded
                 */
                public function __construct(array &$recorded)
                {
                    $this->recorded = &$recorded;
                }

                #[EventHandler]
                public function record(RecordedEvent $event): void
                {
                    $this->recorded[] = $event;
                }
            })
            ->eventBus()
        ;

        $eventBus->publish(new RecordedEvent('stored'));

        self::assertEquals([new RecordedEvent('stored')], $recorded);
    }

    public function test_it_builds_independent_router_implementations_in_the_same_build_directory(): void
    {
        $firstMediator = $this->ultraLight()
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'First hello, ' . $message->name . '!';
                }
            })
            ->mediator()
        ;

        $secondMediator = $this->ultraLight()
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'Second hello, ' . $message->name . '!';
                }
            })
            ->mediator()
        ;

        self::assertSame('First hello, Alice!', $firstMediator->send(new GreetingMessage('Alice')));
        self::assertSame('Second hello, Bob!', $secondMediator->send(new GreetingMessage('Bob')));
    }
}
