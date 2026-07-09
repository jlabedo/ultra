<?php

declare(strict_types=1);

namespace App\TermsOfUse\Event;

use App\TermsOfUse\Support\EventTypes;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event(EventTypes::TERMS_OF_USE_ACCEPTED)]
final readonly class TermsOfUseAccepted
{
    public function __construct(
        #[DomainIdentifier('terms')]
        public string $termsId,
        #[DomainIdentifier('user')]
        public string $userId,
        public string $termsOfUseId,
        public string $url,
    ) {}
}
