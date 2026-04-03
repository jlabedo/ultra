<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\SelfGreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

final class SelfHandlerGreetingService
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }
}

final class MediatorSelfHandlerTest extends MessageHandlingCompilerTestCase
{
    public function test_it_dispatches_self_handling_messages(): void
    {
        $mediator = $this->ultraLight()
            ->withClass(SelfGreetingMessage::class)
            ->mediator();

        self::assertSame('Hello, Alice!', $mediator->send(new SelfGreetingMessage('Alice')));
    }

    public function test_it_injects_services_into_self_handling_methods(): void
    {
        $message = new readonly class('Bob')
        {
            public function __construct(public string $name) {}

            #[SelfHandler]
            public function greet(SelfHandlerGreetingService $greeter): string
            {
                return $greeter->greet($this->name);
            }
        };
        $mediator = $this->ultraLight()
            ->withClass($message::class, SelfHandlerGreetingService::class)
            ->mediator();

        self::assertSame('Hello, Bob!', $mediator->send($message));
    }

    public function test_it_supports_self_handlers_and_regular_handlers_in_the_same_router(): void
    {
        $mediator = $this->ultraLight()
            ->withClass(SelfGreetingMessage::class)
            ->withService(new class() {
                #[Handles]
                public function greet(GreetingMessage $message): string
                {
                    return 'Handler hello, ' . $message->name . '!';
                }
            })
            ->mediator();

        self::assertSame('Handler hello, Alice!', $mediator->send(new GreetingMessage('Alice')));
        self::assertSame('Hello, Carol!', $mediator->send(new SelfGreetingMessage('Carol')));
    }

    public function test_it_applies_advice_to_self_handling_methods(): void
    {
        $log = [];
        $message = new SelfGreetingMessage('Dora');

        $mediator = $this->ultraLight()
            ->withClass(SelfGreetingMessage::class)
            ->withService(new class($log) {
                /**
                 * @param list<object> $log
                 */
                public array $log;

                public function __construct(array &$log)
                {
                    $this->log = &$log;
                }

                #[Before(static function (\ReflectionMethod $method): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function record(object $message, MessageContext $context): void
                {
                    $this->log[] = $message;
                }
            })
            ->mediator();

        self::assertSame('Hello, Dora!', $mediator->send($message));
        self::assertSame([$message], $log);
    }
}
