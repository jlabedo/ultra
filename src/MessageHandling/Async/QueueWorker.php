<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Psr\Container\ContainerInterface;
use Ultra\MessageHandling\Handler\MessageHandlerInterface;

/**
 * Standalone service that drains the async queue by dispatching envelopes
 * to their original handler services.
 */
final readonly class QueueWorker
{
    public function __construct(
        private AsyncQueueInterface $queue,
        private ContainerInterface $container,
    ) {}

    /**
     * Process one envelope from the queue.
     *
     * @return bool True if an envelope was processed, false if queue was empty.
     */
    public function processOne(): bool
    {
        $envelope = $this->queue->dequeue();
        if (null === $envelope) {
            return false;
        }

        /** @var MessageHandlerInterface $handler */
        $handler = $this->container->get($envelope->handlerServiceId);
        $handler->__invoke($envelope->message, $envelope->messageContext);

        return true;
    }

    /**
     * Process all envelopes until the queue is empty.
     *
     * @return int The number of envelopes processed.
     */
    public function drain(): int
    {
        $count = 0;
        while ($this->processOne()) {
            ++$count;
        }

        return $count;
    }
}
