<?php

declare(strict_types=1);

namespace Ultra\Mediator\Attribute;

enum AdviceType: string
{
    case Before = 'before';
    case Around = 'around';
}
