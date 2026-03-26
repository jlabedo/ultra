<?php

declare(strict_types=1);

namespace Ultra\Projection;

interface Projection
{
    public function name(): string;

    public function version(): int;

    public function apply(ProjectionEnvelope $event): void;
}
