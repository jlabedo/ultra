<?php

declare(strict_types=1);

namespace App\Booking\StateStore\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'cw_ss_companies')]
final readonly class CompanyState
{
    public function __construct(
        #[StateId(column: 'company_id')]
        public string $companyId,
        #[StateColumn('name')]
        public string $name,
        #[StateColumn('monthly_minutes')]
        public int $monthlyMinutes,
    ) {}

    public function withMonthlyMinutes(int $monthlyMinutes): self
    {
        return new self($this->companyId, $this->name, $monthlyMinutes);
    }
}
