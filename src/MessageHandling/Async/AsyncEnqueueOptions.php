<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\Time\Duration;

final readonly class AsyncEnqueueOptions
{
    public function __construct(
        public ?AsyncMessageId $messageId = null,
        public ?Duration $delay = null,
    ) {}
}
