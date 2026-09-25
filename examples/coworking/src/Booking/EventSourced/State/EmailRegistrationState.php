<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\State;

use App\Booking\EventSourced\Event\MemberRegistered;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

/**
 * Uniqueness boundary: every event tagged with a given email.
 */
#[EventSourcedEntity(tags: ['email'])]
final class EmailRegistrationState
{
    public private(set) bool $taken = false;

    #[EventSourcingHandler]
    public function onMemberRegistered(MemberRegistered $event): void
    {
        $this->taken = true;
    }
}
