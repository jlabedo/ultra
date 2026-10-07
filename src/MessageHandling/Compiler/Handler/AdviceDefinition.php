<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

use Ultra\MessageHandling\Attribute\MatchResult;

readonly class AdviceDefinition
{
    /**
     * @param \Closure(\Ultra\MessageHandling\Attribute\HandlerMatchContext): ?MatchResult $matcher
     */
    public function __construct(
        public AdviceType $type,
        public int $priority,
        public string $className,
        public string $methodName,
        public string $serviceId,
        public \Closure $matcher,
        public bool $returnsContextPatch = false,
    ) {}
}
