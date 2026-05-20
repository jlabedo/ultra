<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainerFactoryInterface;

abstract class MessageHandlingFunctionalTestCase extends TestCase
{
    /**
     * @return UltraLight<MessageHandlingTestContainer>
     */
    protected function ultraLight(): UltraLight
    {
        /** @var UltraLightContainerFactoryInterface<MessageHandlingTestContainer> $containerFactory */
        $containerFactory = new class implements UltraLightContainerFactoryInterface {
            public function create(Container $container): MessageHandlingTestContainer
            {
                return new MessageHandlingTestContainer($container);
            }
        };

        return new UltraLight()
            ->withBuildDir(dirname(__DIR__, 4).'/var/message_handling_compiler_tests')
            ->withContainerFactory($containerFactory)
            ->withBundle(new MessageHandlingExtension())
            ->withAlias('test.event_bus', EventBusInterface::class)
            ->withAlias('test.mediator', MediatorInterface::class)
            ->withAlias('test.async_queue', InMemoryQueue::class)
            ->withAlias('test.queue_worker', QueueWorker::class)
        ;
    }
}
