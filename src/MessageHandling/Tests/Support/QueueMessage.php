<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests\Support;

final readonly class QueueMessage
{
    public function __construct(
        public string $value,
    ) {}
}
