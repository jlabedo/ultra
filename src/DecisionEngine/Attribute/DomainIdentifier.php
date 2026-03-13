<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class DomainIdentifier
{
    public function __construct(
        public string $name,
    ) {}
}
