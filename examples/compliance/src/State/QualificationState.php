<?php

declare(strict_types=1);

namespace ComplianceExample\State;

use ComplianceExample\Event\QualificationDefined;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;

#[EventSourcedEntity(tags: ['account', 'qualification'])]
final class QualificationState
{
    public private(set) bool $defined = false;

    #[EventSourcingHandler]
    #[MatchTag('account', 'qualification')]
    public function defined(QualificationDefined $event): void
    {
        $this->defined = true;
    }
}
