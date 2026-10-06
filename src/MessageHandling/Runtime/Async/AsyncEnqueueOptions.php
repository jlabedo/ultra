<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

final readonly class AsyncEnqueueOptions
{
    public function __construct(
        public ?AsyncMessageId $messageId = null,
        public ?Duration $delay = null,
        public ?DatePoint $availableAt = null,
        public ?string $key = null,
        public bool $replace = false,
    ) {
        if (null !== $this->delay && null !== $this->availableAt) {
            throw new \InvalidArgumentException('Async enqueue options cannot define both delay and availableAt.');
        }

        if ('' === $this->key) {
            throw new \InvalidArgumentException('Async enqueue key must be null or a non-empty string.');
        }

        if ($this->replace && null === $this->key) {
            throw new \InvalidArgumentException('Async enqueue replacement requires a key.');
        }
    }

    public static function fromDispatch(AsyncDispatch $dispatch, ?AsyncMessageId $messageId = null): self
    {
        return new self(
            messageId: $messageId,
            delay: $dispatch->delay,
            availableAt: $dispatch->availableAt,
            key: $dispatch->key,
            replace: $dispatch->replace,
        );
    }
}
