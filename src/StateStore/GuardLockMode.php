<?php

declare(strict_types=1);

namespace Ultra\StateStore;

enum GuardLockMode
{
    case SHARED;
    case EXCLUSIVE;
}
