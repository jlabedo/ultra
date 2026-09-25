<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\State;

use App\Booking\EventSourced\Event\CompanyPlanChanged;
use App\Booking\EventSourced\Event\CompanyRegistered;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['company'])]
final class CompanyState
{
    public private(set) bool $exists = false;
    public private(set) int $monthlyMinutes = 0;

    #[EventSourcingHandler]
    public function onRegistered(CompanyRegistered $event): void
    {
        $this->exists = true;
        $this->monthlyMinutes = $event->monthlyMinutes;
    }

    #[EventSourcingHandler]
    public function onPlanChanged(CompanyPlanChanged $event): void
    {
        $this->monthlyMinutes = $event->monthlyMinutes;
    }
}
