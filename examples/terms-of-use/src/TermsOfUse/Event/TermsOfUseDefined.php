<?php

declare(strict_types=1);

namespace App\TermsOfUse\Event;

use App\TermsOfUse\Support\EventTypes;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event(EventTypes::TERMS_OF_USE_DEFINED)]
final readonly class TermsOfUseDefined
{
    public function __construct(
        #[DomainIdentifier('terms')]
        public string $termsId,
        public string $termsOfUseId,
        public string $url,
    ) {}
}
