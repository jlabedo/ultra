<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

interface AsyncMessageSender
{
    public function enqueue(AsyncEnvelope $envelope, ?AsyncEnqueueOptions $options = null): AsyncMessageId;
}
