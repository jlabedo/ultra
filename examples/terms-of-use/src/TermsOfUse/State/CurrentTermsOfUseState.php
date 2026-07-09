<?php

declare(strict_types=1);

namespace App\TermsOfUse\State;

use App\TermsOfUse\Event\TermsOfUseDefined;
use App\TermsOfUse\TermsOfUse;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['terms'])]
final class CurrentTermsOfUseState
{
    private ?TermsOfUse $currentTermsOfUse = null;

    public function currentTermsOfUse(): ?TermsOfUse
    {
        return $this->currentTermsOfUse;
    }

    #[EventSourcingHandler]
    public function onTermsOfUseDefined(TermsOfUseDefined $event): void
    {
        $this->currentTermsOfUse = new TermsOfUse($event->termsOfUseId, $event->url);
    }
}
