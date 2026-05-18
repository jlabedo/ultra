<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Attribute;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class BindTo
{
    public function __construct(
        public string $property,
    ) {}
}
