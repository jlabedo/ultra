<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final readonly class DomainIdentifier
{
    public function __construct(
        public string $name,
    ) {}
}
