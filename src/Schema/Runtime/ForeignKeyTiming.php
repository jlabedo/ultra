<?php

declare(strict_types=1);

namespace Ultra\Schema;

enum ForeignKeyTiming
{
    case NotDeferrable;
    case InitiallyImmediate;
    case InitiallyDeferred;
}
