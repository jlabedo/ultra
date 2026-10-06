<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Exception\NoHandlerFound;
use Ultra\MessageHandling\Exception\UnknownEvent;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\FarewellMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\RecordedEvent;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_Compiler')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class CompilationContractTest extends MessageHandlingCompilerTestCase
{
    public function test_it_requires_around_advices_to_return_proceed_result_or_mixed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Around advice "class@anonymous.*::execute" must declare return type "'.preg_quote(ProceedResult::class, '/').'"\./');

        $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return $message->name;
                }
            })
            ->withService(new class {
                #[Around]
                public function execute(callable $proceed, object $message, MessageContext $context): string
                {
                    return $proceed()->value;
                }
            })
            ->boot()
        ;
    }

    public function test_it_requires_non_around_context_mutating_advices_to_return_patch_arrays(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Before advice "class@anonymous.*::log" must declare no return type, "void", or return type "array" \(optionally nullable\)\./');

        $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return $message->name;
                }
            })
            ->withService(new class {
                #[Before]
                public function log(object $message, MessageContext $context): string
                {
                    return 'invalid';
                }
            })
            ->boot()
        ;
    }

    public function test_it_builds_independent_router_implementations_in_the_same_build_directory(): void
    {
        $firstMediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'First hello, '.$message->name.'!';
                }
            })
            ->boot()->mediator()
        ;

        $secondMediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'Second hello, '.$message->name.'!';
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('First hello, Alice!', $firstMediator->send(new GreetingMessage('Alice')));
        self::assertSame('Second hello, Bob!', $secondMediator->send(new GreetingMessage('Bob')));
    }

    public function test_missing_async_sender_channel_fails_during_compilation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No async message sender is registered for channel "missing".');

        $this->ultraLight()
            ->withService(new class {
                #[Handles]
                #[Asynchronous('missing')]
                public function handle(GreetingMessage $message): void {}
            })
            ->boot()
        ;
    }

    public function test_it_loads_identical_empty_routers_from_different_build_directories(): void
    {
        $first = $this->ultraLight()
            ->withBuildDir(dirname(__DIR__, 3).'/var/message_handling_compiler_tests/empty-first')
            ->boot();
        $second = $this->ultraLight()
            ->withBuildDir(dirname(__DIR__, 3).'/var/message_handling_compiler_tests/empty-second')
            ->boot();

        foreach ([$first, $second] as $application) {
            try {
                $application->mediator()->send(new GreetingMessage('unknown'));
                self::fail('An empty mediator must reject unknown messages.');
            } catch (NoHandlerFound $exception) {
                self::assertStringContainsString(GreetingMessage::class, $exception->getMessage());
            }

            try {
                $application->eventBus()->publish(new GreetingMessage('unknown'));
                self::fail('An empty event bus must reject unknown events.');
            } catch (UnknownEvent $exception) {
                self::assertStringContainsString(GreetingMessage::class, $exception->getMessage());
            }
        }
    }

    public function test_it_keeps_handler_services_independent_across_identical_compilations(): void
    {
        $handler = static fn (string $prefix): object => new class($prefix) {
            /** @var list<string> */
            public array $events = [];

            public function __construct(private string $prefix) {}

            #[Handles]
            public function greet(GreetingMessage $message): string
            {
                return $this->prefix.' hello, '.$message->name.'!';
            }

            #[Handles]
            public function farewell(FarewellMessage $message, MediatorInterface $mediator): string
            {
                return $mediator->send(new GreetingMessage($message->name));
            }

            #[EventHandler]
            public function record(RecordedEvent $event): void
            {
                $this->events[] = $this->prefix.':'.$event->payload;
            }
        };
        $firstHandler = $handler('First');
        $secondHandler = $handler('Second');
        $application = $this->ultraLight()->withEventClass(RecordedEvent::class);
        $first = $application
            ->withBuildDir(dirname(__DIR__, 3).'/var/message_handling_compiler_tests/handlers-first')
            ->withService($firstHandler, 'greeting-handler')
            ->boot();
        $second = $application
            ->withBuildDir(dirname(__DIR__, 3).'/var/message_handling_compiler_tests/handlers-second')
            ->withService($secondHandler, 'greeting-handler')
            ->boot();

        self::assertSame('First hello, Alice!', $first->mediator()->send(new GreetingMessage('Alice')));
        self::assertSame('Second hello, Bob!', $second->mediator()->send(new GreetingMessage('Bob')));
        self::assertSame('First hello, Carol!', $first->mediator()->send(new FarewellMessage('Carol')));
        self::assertSame('Second hello, Dan!', $second->mediator()->send(new FarewellMessage('Dan')));
        $first->eventBus()->publish(new RecordedEvent('first'));
        $second->eventBus()->publish(new RecordedEvent('second'));
        self::assertSame(['First:first'], $firstHandler->events);
        self::assertSame(['Second:second'], $secondHandler->events);
    }
}
