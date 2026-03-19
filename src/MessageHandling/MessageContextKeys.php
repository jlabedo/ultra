<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

final class MessageContextKeys
{
    public const MESSAGE_ID = 'message_id';
    public const CORRELATION_ID = 'correlation_id';
    public const CAUSATION_ID = 'causation_id';
}
