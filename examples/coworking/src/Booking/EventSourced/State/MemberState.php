<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\State;

use App\Booking\EventSourced\Event\MemberRegistered;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['member'])]
final class MemberState
{
    public private(set) bool $exists = false;
    public private(set) string $companyId = '';

    #[EventSourcingHandler]
    public function onRegistered(MemberRegistered $event): void
    {
        $this->exists = true;
        $this->companyId = $event->companyId;
    }
}
