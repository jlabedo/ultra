<?php

declare(strict_types=1);

namespace ComplianceExample\Refresh;

final class Scope
{
    public const string SUBJECT = 'subject:';
    public const string QUALIFICATION = 'qualification:';

    public static function channel(string $account): string
    {
        return 'compliance:' . $account;
    }

    public static function subject(string $subject): string
    {
        return self::SUBJECT . $subject;
    }

    public static function qualification(string $qualification): string
    {
        return self::QUALIFICATION . $qualification;
    }
}
