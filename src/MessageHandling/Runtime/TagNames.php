<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

final class TagNames
{
    public const ROUTER = 'ultra.message_router';
    public const HANDLER = 'ultra.message_handler';
    public const SELF_HANDLER = 'ultra.message_self_handler';
    public const ASPECT = 'ultra.message_aspect';
    public const ASYNC_SENDER = 'ultra.message_async_sender';
}
