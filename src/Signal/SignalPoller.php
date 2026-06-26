<?php

declare(strict_types=1);

namespace Ultra\Signal;

use Ultra\Time\Duration;

final class SignalPoller
{
    /** @var list<SignalHandler> */
    private array $handlers;

    private string $workerId;

    private int $nextHandlerIndex = 0;

    /**
     * @param iterable<SignalHandler> $handlers
     */
    public function __construct(
        private readonly SignalStore $signalStore,
        iterable $handlers,
        private readonly int $perQueueLimit = 64,
        private readonly Duration $deliveryTimeout = new Duration(seconds: 5),
        ?string $workerId = null,
    ) {
        if ($perQueueLimit <= 0) {
            throw new \InvalidArgumentException('Signal poller per-queue limit must be positive.');
        }

        if (null !== $workerId && '' === $workerId) {
            throw new \InvalidArgumentException('Signal poller worker id must be null or a non-empty string.');
        }

        $this->handlers = array_values(is_array($handlers) ? $handlers : iterator_to_array($handlers, false));
        $this->validateHandlers($this->handlers);
        $this->workerId = $workerId ?? (gethostname() ?: 'host').'-'.bin2hex(random_bytes(8));
    }

    /**
     * Claim hints from registered queues and route them to their handlers.
     *
     * @return int number of hints delivered to handlers
     */
    public function pollOnce(): int
    {
        if ([] === $this->handlers) {
            return 0;
        }

        $delivered = 0;
        $handlerCount = count($this->handlers);
        $start = $this->nextHandlerIndex;
        $this->nextHandlerIndex = ($this->nextHandlerIndex + 1) % $handlerCount;

        for ($offset = 0; $offset < $handlerCount; ++$offset) {
            $handler = $this->handlers[($start + $offset) % $handlerCount];
            $hints = $this->signalStore->claimHints(
                [$handler->queueName()],
                $this->workerId,
                $this->perQueueLimit,
                $this->deliveryTimeout,
            );

            if ([] === $hints) {
                continue;
            }

            $handler->handleSignalHints($hints);
            $delivered += count($hints);
        }

        return $delivered;
    }

    /**
     * @param list<SignalHandler> $handlers
     */
    private function validateHandlers(array $handlers): void
    {
        $queueNames = [];

        foreach ($handlers as $handler) {
            $queueName = $handler->queueName();
            if ('' === $queueName) {
                throw new \InvalidArgumentException('Signal handler queue name must be non-empty.');
            }

            if (isset($queueNames[$queueName])) {
                throw new \InvalidArgumentException(sprintf('Duplicate signal handler for queue "%s".', $queueName));
            }

            $queueNames[$queueName] = true;
        }
    }
}
