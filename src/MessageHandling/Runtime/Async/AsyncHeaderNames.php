<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

final class AsyncHeaderNames
{
    public const string CONTENT_ENCODING = 'content-encoding';
    public const string CONTENT_TYPE = 'content-type';
    public const string MESSAGE_TYPE = 'ultra.message.type';
    public const string ROUTE = 'ultra.async.route';

    private function __construct() {}
}
