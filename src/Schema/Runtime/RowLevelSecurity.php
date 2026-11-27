<?php

declare(strict_types=1);

namespace Ultra\Schema;

enum RowLevelSecurity
{
    case Disabled;
    case Enabled;
    case Forced;
}
