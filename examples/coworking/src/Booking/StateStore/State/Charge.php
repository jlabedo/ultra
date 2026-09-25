<?php

declare(strict_types=1);

namespace App\Booking\StateStore\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'cw_ss_company_month_charges')]
final readonly class Charge
{
    public function __construct(
        #[StateId(column: 'booking_id')]
        public string $bookingId,
        #[StateColumn('minutes')]
        public int $minutes,
    ) {}
}
