<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

final readonly class Pointcut
{
    public static function all(\ReflectionMethod $m): MatchResult
    {
        return MatchResult::apply();
    }
}
