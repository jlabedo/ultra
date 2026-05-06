<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_PostgresQueue;

use Ultra\MessageHandling\Async\AsyncHeaders;

final readonly class EncodedAsyncEnvelope
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $body,
        public AsyncHeaders $headers,
        public array $attributes,
    ) {}
}
