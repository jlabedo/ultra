<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

readonly class AdviceDefinition
{
    /**
     * @param \Closure(\ReflectionMethod): ?\Ultra\MessageHandling\Attribute\MatchResult $matcher
     */
    public function __construct(
        public AdviceType $type,
        public int $priority,
        public string $className,
        public string $methodName,
        public string $serviceId,
        public \Closure $matcher,
    ) {}
}
