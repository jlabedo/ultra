<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

final class MessageContextPropagator implements MessageContextPropagatorInterface
{
    public function normalizeContext(MessageContext $context): MessageContext
    {
        $messageId = $this->stringOrNull($context->get(MessageContextKeys::MESSAGE_ID));
        if (null !== $messageId) {
            return $context->with(MessageContextKeys::MESSAGE_ID, $messageId)->with(
                MessageContextKeys::CORRELATION_ID,
                $this->stringOrNull($context->get(MessageContextKeys::CORRELATION_ID)) ?? $messageId,
            );
        }

        $messageId = $this->generateMessageId();
        $correlationId = $this->stringOrNull($context->get(MessageContextKeys::CORRELATION_ID)) ?? $messageId;

        return $context->with(MessageContextKeys::MESSAGE_ID, $messageId)->with(
            MessageContextKeys::CORRELATION_ID,
            $correlationId,
        );
    }

    public function createChildContext(MessageContext $parentContext): MessageContext
    {
        $parentContext = $this->normalizeContext($parentContext);

        $messageId = $this->generateMessageId();
        $correlationId = $this->stringOrNull($parentContext->get(MessageContextKeys::CORRELATION_ID)) ?? $messageId;

        return $parentContext
            ->with(MessageContextKeys::MESSAGE_ID, $messageId)
            ->with(MessageContextKeys::CORRELATION_ID, $correlationId)
            ->with(MessageContextKeys::CAUSATION_ID, $parentContext->get(MessageContextKeys::MESSAGE_ID));
    }

    private function generateMessageId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
