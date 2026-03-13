<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler;

enum AdviceType: string
{
    case Before = 'before';
    case Around = 'around';
    case AfterReturning = 'after_returning';
    case AfterThrowing = 'after_throwing';
    case After = 'after';
}
