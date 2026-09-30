<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'cw_direct_companies')]
final readonly class Company
{
    public function __construct(
        #[StateId]
        public string $id,
        public string $name,
        #[StateColumn('monthly_minutes')]
        public int $monthlyMinutes,
    ) {}

    public function withMonthlyMinutes(int $monthlyMinutes): self
    {
        return new self($this->id, $this->name, $monthlyMinutes);
    }
}
