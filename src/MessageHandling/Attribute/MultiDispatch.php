<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class MultiDispatch
{
    /**
     * @param \Closure(\ReflectionMethod): ?RouteMatch $matcher
     * @param string $messageTag Resource tag used to discover known message classes via is_a() routing
     */
    public function __construct(
        public \Closure $matcher,
        public string $messageTag,
        public string $noHandlerExceptionClass = \RuntimeException::class,
    ) {}
}
