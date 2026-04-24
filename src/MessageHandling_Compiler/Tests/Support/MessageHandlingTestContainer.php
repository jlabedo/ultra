<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests\Support;

use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\Testing\UltraLightContainer;

readonly class MessageHandlingTestContainer extends UltraLightContainer
{
    public function mediator(): MediatorInterface
    {
        return $this->get('test.mediator');
    }

    public function eventBus(): EventBusInterface
    {
        return $this->get('test.event_bus');
    }

    public function asyncQueue(): InMemoryQueue
    {
        return $this->get('test.async_queue');
    }

    public function queueWorker(): QueueWorker
    {
        return $this->get('test.queue_worker');
    }
}
