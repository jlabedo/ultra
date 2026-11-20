<?php

declare(strict_types=1);

namespace ComplianceExample\State;

use ComplianceExample\Event\EvidenceProvided;
use ComplianceExample\Event\EvidenceWithdrawn;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;

#[EventSourcedEntity(tags: ['account', 'subject', 'evidence'])]
final class EvidenceState
{
    public private(set) EvidenceStatus $status = EvidenceStatus::Unused;

    #[EventSourcingHandler]
    #[MatchTag('account', 'subject', 'evidence')]
    public function provided(EvidenceProvided $event): void
    {
        $this->status = EvidenceStatus::Active;
    }

    #[EventSourcingHandler]
    #[MatchTag('account', 'subject', 'evidence')]
    public function withdrawn(EvidenceWithdrawn $event): void
    {
        $this->status = EvidenceStatus::Withdrawn;
    }
}
