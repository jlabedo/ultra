<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

final readonly class CommandMetadata
{
    /**
     * @param list<StateParameterMetadata>   $stateParameters
     * @param list<ServiceParameterMetadata> $serviceParameters
     */
    public function __construct(
        public \ReflectionMethod $decideMethod,
        public ?\ReflectionMethod $checkMethod,
        public array $stateParameters,
        public array $serviceParameters,
    ) {
    }
}
