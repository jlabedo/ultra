<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('coworking.company_registered')]
final readonly class CompanyRegistered
{
    public function __construct(
        #[DomainIdentifier('company')]
        public string $companyId,
        public string $name,
        public int $monthlyMinutes,
    ) {}
}
