<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Psr\Container\ContainerInterface;
use Ultra\MessageHandling\Handler\MessageHandlerInterface;
use Ultra\UnitOfWork\UnitOfWorkRunner;

/**
 * Standalone service that drains the async queue by dispatching envelopes
 * to their original handler services.
 */
final readonly class QueueWorker
{
    public function __construct(
        private AsyncMessageReceiver $receiver,
        private ContainerInterface $container,
        private ?UnitOfWorkRunner $unitOfWorkRunner = null,
    ) {}

    /**
     * Process one delivery from the queue.
     *
     * @return bool true if a delivery was processed, false if queue was empty
     */
    public function processOne(): bool
    {
        $delivery = $this->receiver->reserve();
        if (null === $delivery) {
            return false;
        }

        $envelope = $delivery->envelope;
        $route = $envelope->headers->getString(AsyncHeaderNames::ROUTE);
        if (null === $route || '' === $route) {
            $this->receiver->release($delivery);

            throw new \LogicException(sprintf(
                'Async delivery "%s" is missing the "%s" route header.',
                $delivery->messageId->value,
                AsyncHeaderNames::ROUTE,
            ));
        }

        try {
            /** @var MessageHandlerInterface $handler */
            $handler = $this->container->get($route);

            $work = function () use ($handler, $envelope, $delivery): void {
                $handler->__invoke($envelope->message, $envelope->messageContext);
                $this->receiver->acknowledge($delivery);
            };

            if (null !== $this->unitOfWorkRunner) {
                $this->unitOfWorkRunner->run($work);
            } else {
                $work();
            }
        } catch (\Throwable $e) {
            $this->receiver->release($delivery, reason: $e);

            throw $e;
        }

        return true;
    }

    /**
     * Process all envelopes until the queue is empty.
     *
     * @return int the number of envelopes processed
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
