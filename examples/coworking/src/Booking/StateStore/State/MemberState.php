<?php

declare(strict_types=1);

namespace App\Booking\StateStore\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'cw_ss_members')]
final readonly class MemberState
{
    public function __construct(
        #[StateId(column: 'member_id')]
        public string $memberId,
        #[StateColumn('company_id')]
        public string $companyId,
        #[StateColumn('email')]
        public string $email,
    ) {}
}
