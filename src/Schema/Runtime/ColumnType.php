<?php

declare(strict_types=1);

namespace Ultra\Schema;

enum ColumnType
{
    case Text;
    case Int32;
    case Int64;
    case Boolean;
}
