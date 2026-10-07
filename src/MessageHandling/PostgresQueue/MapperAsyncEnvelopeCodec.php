<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_PostgresQueue;

use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncHeaderNames;
use Ultra\MessageHandling\Async\AsyncHeaders;
use Ultra\MessageHandling\MessageContext;

final readonly class MapperAsyncEnvelopeCodec implements AsyncEnvelopeCodecInterface
{
    public function __construct(
        private UltraNormalizer $normalizer,
    ) {}

    public function encode(AsyncEnvelope $envelope): EncodedAsyncEnvelope
    {
        $headers = $envelope->headers->with(AsyncHeaderNames::CONTENT_TYPE, 'application/json')->with(
            AsyncHeaderNames::MESSAGE_TYPE,
            $envelope->message::class,
        );

        return new EncodedAsyncEnvelope(
            $this->encodeJson($this->normalizer->toScalar($envelope->message), 'async message body'),
            $headers,
            $envelope->messageContext->propagated()->all(),
        );
    }

    public function decode(string $body, AsyncHeaders $headers, array $attributes): AsyncEnvelope
    {
        $messageType = $headers->getString(AsyncHeaderNames::MESSAGE_TYPE);
        if (null === $messageType || '' === $messageType) {
            throw new \RuntimeException(sprintf(
                'Encoded async message is missing the "%s" header.',
                AsyncHeaderNames::MESSAGE_TYPE,
            ));
        }

        if (!class_exists($messageType)) {
            throw new \RuntimeException(sprintf('Encoded async message type "%s" does not exist.', $messageType));
        }

        return new AsyncEnvelope(
            $this->normalizer->fromScalar($this->decodeJson($body, 'async message body'), $messageType),
            MessageContext::fromArray($attributes),
            $headers,
        );
    }

    private function encodeJson(mixed $value, string $description): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Unable to encode %s as JSON.', $description), previous: $e);
        }
    }

    private function decodeJson(string $json, string $description): array|bool|float|int|string|null
    {
        try {
            /** @var array|bool|float|int|string|null */
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Unable to decode %s from JSON.', $description), previous: $e);
        }
    }
}
