<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('coworking.company_plan_changed')]
final readonly class CompanyPlanChanged
{
    public function __construct(
        #[DomainIdentifier('company')]
        public string $companyId,
        public int $monthlyMinutes,
    ) {}
}
