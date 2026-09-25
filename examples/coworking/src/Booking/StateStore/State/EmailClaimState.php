<?php

declare(strict_types=1);

namespace App\Booking\StateStore\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

/**
 * Uniqueness boundary: one row per normalized email.
 */
#[State(table: 'cw_ss_member_emails')]
final readonly class EmailClaimState
{
    public function __construct(
        #[StateId(column: 'email')]
        public string $email,
        #[StateColumn('member_id')]
        public string $memberId,
    ) {}
}
