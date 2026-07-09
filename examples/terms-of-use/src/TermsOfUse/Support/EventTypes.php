<?php

declare(strict_types=1);

namespace App\TermsOfUse\Support;

final class EventTypes
{
    public const TERMS_OF_USE_DEFINED = 'terms_of_use.defined';
    public const TERMS_OF_USE_ACCEPTED = 'terms_of_use.accepted';

    private function __construct() {}
}
