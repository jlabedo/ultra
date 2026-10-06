<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\MessageHandling\Async\AsyncDispatch;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncMessageId;
use Ultra\MessageHandling\Async\AsyncMessageSender;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\TagNames;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\EmptyMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\FarewellMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\RecordedEvent;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;
use Ultra\Time\DatePoint;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_Compiler')]
final class AsyncRoutingTest extends MessageHandlingCompilerTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function eventDeliveryModes(): iterable
    {
        yield 'mixed inline and queued' => [false];
        yield 'all queued' => [true];
    }

    #[DataProvider('eventDeliveryModes')]
    public function test_event_subscriptions_queue_their_own_routes_and_deliver_once_in_priority_order(bool $firstAsync): void
    {
        $log = new EventDeliveryLog();
        $container = $this->ultraLight()
            ->withEventClass(RecordedEvent::class)
            ->withService($firstAsync ? new FirstQueuedListener($log) : new InlineListener($log))
            ->withService(new SecondQueuedListener($log))
            ->boot();
        $container->eventBus()->publish(new RecordedEvent('payload'));
        self::assertSame($firstAsync ? [] : ['first:payload'], $log->entries);
        self::assertSame($firstAsync ? 2 : 1, $container->asyncQueue()->count());
        self::assertSame($firstAsync ? 2 : 1, $container->queueWorker()->drain());
        self::assertSame(['first:payload', 'second:payload'], $log->entries);
        self::assertTrue($container->asyncQueue()->isEmpty());
        self::assertFalse($container->queueWorker()->processOne());
        self::assertSame(0, $container->queueWorker()->drain());
    }

    public function test_sync_and_async_handlers_on_different_messages(): void
    {
        $syncExecuted = false;

        $container = $this->ultraLight()
            ->withService(new class($syncExecuted) {
                public bool $syncExecuted;

                public function __construct(bool &$syncExecuted)
                {
                    $this->syncExecuted = &$syncExecuted;
                }

                #[Handles]
                public function greet(GreetingMessage $message): string
                {
                    $this->syncExecuted = true;

                    return 'Hello, '.$message->name.'!';
                }

                #[Handles]
                #[Asynchronous]
                public function farewell(FarewellMessage $message): string
                {
                    return 'Goodbye, '.$message->name.'!';
                }
            })
            ->boot()
        ;

        // Sync handler returns result immediately
        $syncResult = $container->mediator()->send(new GreetingMessage('Alice'));
        self::assertSame('Hello, Alice!', $syncResult);
        self::assertTrue($syncExecuted);
        self::assertSame(0, $container->asyncQueue()->count());

        // Async handler returns null and enqueues
        $asyncResult = $container->mediator()->send(new FarewellMessage('Bob'));
        self::assertNull($asyncResult);
        self::assertSame(1, $container->asyncQueue()->count());
    }

    public function test_async_dispatch_context_can_schedule_async_handler(): void
    {
        $executed = false;

        $container = $this->ultraLight()
            ->withService(new class($executed) {
                public bool $executed;

                public function __construct(bool &$executed)
                {
                    $this->executed = &$executed;
                }

                #[Handles]
                #[Asynchronous]
                public function handle(EmptyMessage $message): void
                {
                    $this->executed = true;
                }
            })
            ->boot()
        ;

        $container->mediator()->send(
            new EmptyMessage(),
            AsyncDispatch::scheduledAt(DatePoint::create(3000, 1, 1))->asContext(),
        );

        self::assertSame(1, $container->asyncQueue()->count());
        self::assertSame(0, $container->queueWorker()->drain());
        self::assertFalse($executed);
    }

    public function test_async_handlers_are_routed_to_their_named_sender_channel(): void
    {
        $sender = new RecordingAsyncSender();
        $executed = false;

        $container = $this->ultraLight()
            ->withService($sender, 'test.email_sender')
            ->withBundle(new class implements ContainerExtension {
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
}

final class EventDeliveryLog
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class InlineListener
{
    public function __construct(private EventDeliveryLog $log) {}

    #[EventHandler(priority: 20)]
    public function handle(RecordedEvent $event): void
    {
        $this->log->entries[] = 'first:'.$event->payload;
    }
}

final readonly class FirstQueuedListener
{
    public function __construct(private EventDeliveryLog $log) {}

    #[EventHandler(priority: 20)]
    #[Asynchronous]
    public function handle(RecordedEvent $event): void
    {
        $this->log->entries[] = 'first:'.$event->payload;
    }
}

final readonly class SecondQueuedListener
{
    public function __construct(private EventDeliveryLog $log) {}

    #[EventHandler(priority: 10)]
    #[Asynchronous]
    public function handle(RecordedEvent $event): void
    {
        $this->log->entries[] = 'second:'.$event->payload;
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
