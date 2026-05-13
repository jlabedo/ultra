<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Async\AsyncMessageId;
use Ultra\MessageHandling\Async\AsyncMessageSender;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\TagNames;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\RecordedEvent;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class MessageHandlingBundleTest extends MessageHandlingCompilerTestCase
{
    public function test_it_autoconfigures_anonymous_handler_services(): void
    {
        $mediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'Hello, '.$message->name.'!';
                }
            })
            ->boot()->mediator()
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
            ->boot()->eventBus()
        ;

        $eventBus->publish(new RecordedEvent('stored'));

        self::assertEquals([new RecordedEvent('stored')], $recorded);
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

    public function test_async_handlers_are_routed_to_their_named_sender_channel(): void
    {
        $sender = new RecordingAsyncSender();
        $executed = false;

        $container = $this->ultraLight()
            ->withService($sender, 'test.email_sender')
            ->withBundle(new class extends Bundle {
                public function build(ContainerBuilder $container): void
                {
                    $container->getDefinition('test.email_sender')
                        ->addTag(TagNames::ASYNC_SENDER, ['channel' => 'emails'])
                    ;
                }
            })
            ->withService(new class($executed) {
                public bool $executed;

                public function __construct(bool &$executed)
                {
                    $this->executed = &$executed;
                }

                #[Handles]
                #[Asynchronous('emails')]
                public function handle(GreetingMessage $message): void
                {
                    $this->executed = true;
                }
            })
            ->boot()
        ;

        $container->mediator()->send(new GreetingMessage('Alice'));

        self::assertFalse($executed);
        self::assertCount(1, $sender->envelopes);
        self::assertInstanceOf(GreetingMessage::class, $sender->envelopes[0]->message);
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
}

final class RecordingAsyncSender implements AsyncMessageSender
{
    /** @var list<AsyncEnvelope> */
    public array $envelopes = [];

    public function enqueue(AsyncEnvelope $envelope, ?AsyncEnqueueOptions $options = null): AsyncMessageId
    {
        $this->envelopes[] = $envelope;

        return $options?->messageId ?? AsyncMessageId::generate();
    }
}
