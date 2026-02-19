<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

final readonly class ServiceParameterMetadata
{
    public function __construct(
        public string $parameterName,
        public string $serviceClass,
    ) {
    }
}
