<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

final readonly class Violation
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}
}
