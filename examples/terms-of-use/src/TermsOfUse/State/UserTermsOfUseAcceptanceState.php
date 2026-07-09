<?php

declare(strict_types=1);

namespace App\TermsOfUse\State;

use App\TermsOfUse\Event\TermsOfUseAccepted;
use App\TermsOfUse\TermsOfUse;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['user'])]
final class UserTermsOfUseAcceptanceState
{
    private ?TermsOfUse $acceptedTermsOfUse = null;

    public function acceptedTermsOfUse(): ?TermsOfUse
    {
        return $this->acceptedTermsOfUse;
    }

    #[EventSourcingHandler]
    public function onTermsOfUseAccepted(TermsOfUseAccepted $event): void
    {
        $this->acceptedTermsOfUse = new TermsOfUse($event->termsOfUseId, $event->url);
    }
}
