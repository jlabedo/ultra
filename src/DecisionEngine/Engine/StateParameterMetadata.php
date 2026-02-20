<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

final readonly class StateParameterMetadata
{
    public function __construct(
        public string $parameterName,
        public string $stateClass,
        public string $domainIdentifierName,
        public string $commandProperty,
        public bool $isNullable,
    ) {
    }
}
