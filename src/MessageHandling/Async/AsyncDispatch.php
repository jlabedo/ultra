<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\MessageHandling\MessageContext;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

final readonly class AsyncDispatch
{
    private function __construct(
        public ?Duration $delay,
        public ?DatePoint $availableAt,
        public ?string $key,
        public bool $replace,
    ) {
        if (null !== $this->delay && null !== $this->availableAt) {
            throw new \InvalidArgumentException('Async dispatch cannot define both delay and availableAt.');
        }

        if ('' === $this->key) {
            throw new \InvalidArgumentException('Async dispatch key must be null or a non-empty string.');
        }

        if ($this->replace && null === $this->key) {
            throw new \InvalidArgumentException('Async dispatch replacement requires a key.');
        }
    }

    public static function delayedBy(Duration $delay, ?string $key = null, bool $replace = false): self
    {
        return new self($delay, null, $key, $replace);
    }

    public static function scheduledAt(DatePoint $availableAt, ?string $key = null, bool $replace = false): self
    {
        return new self(null, $availableAt, $key, $replace);
    }

    public function asContext(): MessageContext
    {
        return MessageContext::empty()->withLocal(self::class, $this);
    }

    public static function fromContext(MessageContext $context): ?self
    {
        $dispatch = $context->local(self::class);

        return $dispatch instanceof self ? $dispatch : null;
    }
}
