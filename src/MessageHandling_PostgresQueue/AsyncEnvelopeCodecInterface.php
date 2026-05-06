<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_PostgresQueue;

use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncHeaders;

interface AsyncEnvelopeCodecInterface
{
    public function encode(AsyncEnvelope $envelope): EncodedAsyncEnvelope;

    /**
     * @param array<string, mixed> $attributes
     */
    public function decode(string $body, AsyncHeaders $headers, array $attributes): AsyncEnvelope;
}
