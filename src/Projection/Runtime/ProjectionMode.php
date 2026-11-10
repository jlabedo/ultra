<?php

declare(strict_types=1);

namespace Ultra\Projection;

enum ProjectionMode
{
    case Asynchronous;
    case Synchronous;
    case SynchronousFromNow;
}
