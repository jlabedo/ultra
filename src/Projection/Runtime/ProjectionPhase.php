<?php

declare(strict_types=1);

namespace Ultra\Projection;

enum ProjectionPhase: string
{
    case Building = 'building';
    case Live = 'live';
}
