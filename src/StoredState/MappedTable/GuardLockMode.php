<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

enum GuardLockMode
{
    case SHARED;
    case EXCLUSIVE;
}
