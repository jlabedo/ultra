<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Before;

enum AdviceType: string
{
    case Before = 'before';
    case Around = 'around';
    case AfterReturning = 'after_returning';
    case AfterThrowing = 'after_throwing';
    case After = 'after';

    public static function fromAttribute(object $attribute): self
    {
        return match (true) {
            $attribute instanceof Before => self::Before,
            $attribute instanceof Around => self::Around,
            $attribute instanceof AfterReturning => self::AfterReturning,
            $attribute instanceof AfterThrowing => self::AfterThrowing,
            $attribute instanceof After => self::After,
            default => throw new \InvalidArgumentException('Unsupported attribute type'),
        };
    }
}
