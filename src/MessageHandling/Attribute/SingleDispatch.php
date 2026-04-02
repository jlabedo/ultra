<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class SingleDispatch
{
    /**
     * @param \Closure(\ReflectionMethod): ?RouteMatch $matcher
     */
    public function __construct(
        public \Closure $matcher,
        public string $noHandlerExceptionClass = \RuntimeException::class,
    ) {}
}
