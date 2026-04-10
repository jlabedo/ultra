<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

final class MessageContextPropagator implements MessageContextPropagatorInterface
{
    public function createDispatchContext(MessageContext $context): MessageContext
    {
        if ($context->has(MessageContextKeys::CAUSATION_ID)) {
            $messageId = $this->stringOrNull($context->get(MessageContextKeys::MESSAGE_ID))
                ?? $this->generateMessageId();
            $correlationId = $this->stringOrNull($context->get(MessageContextKeys::CORRELATION_ID))
                ?? $messageId;

            return $context
                ->with(MessageContextKeys::MESSAGE_ID, $messageId)
                ->with(MessageContextKeys::CORRELATION_ID, $correlationId)
            ;
        }

        if ($context->has(MessageContextKeys::MESSAGE_ID)) {
            $parentMessageId = $this->stringOrNull($context->get(MessageContextKeys::MESSAGE_ID));
            $messageId = $this->generateMessageId();
            $correlationId = $this->stringOrNull($context->get(MessageContextKeys::CORRELATION_ID))
                ?? $parentMessageId
                ?? $messageId;

            $normalized = $context
                ->with(MessageContextKeys::MESSAGE_ID, $messageId)
                ->with(MessageContextKeys::CORRELATION_ID, $correlationId)
            ;

            if (null !== $parentMessageId) {
                $normalized = $normalized->with(MessageContextKeys::CAUSATION_ID, $parentMessageId);
            }

            return $normalized;
        }

        $messageId = $this->generateMessageId();
        $correlationId = $this->stringOrNull($context->get(MessageContextKeys::CORRELATION_ID))
            ?? $messageId;

        return $context
            ->with(MessageContextKeys::MESSAGE_ID, $messageId)
            ->with(MessageContextKeys::CORRELATION_ID, $correlationId)
        ;
    }

    private function generateMessageId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== $value
            ? $value
            : null;
    }
}
