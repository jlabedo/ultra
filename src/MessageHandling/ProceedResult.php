<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

final readonly class ProceedResult
{
    public function __construct(
        public mixed $value,
        public MessageContext $context,
    ) {}

    public function withValue(mixed $value): self
    {
        return new self($value, $this->context);
    }

    public function withContext(MessageContext $context): self
    {
        return new self($this->value, $context);
    }
}
