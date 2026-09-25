<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('coworking.member_registered')]
final readonly class MemberRegistered
{
    public function __construct(
        #[DomainIdentifier('member')]
        public string $memberId,
        #[DomainIdentifier('company')]
        public string $companyId,
        #[DomainIdentifier('email')]
        public string $email,
    ) {}
}
