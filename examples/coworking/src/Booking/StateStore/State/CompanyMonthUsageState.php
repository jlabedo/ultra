<?php

declare(strict_types=1);

namespace App\Booking\StateStore\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateId;

/**
 * Consistency boundary for the pooled quota: minutes charged per booking for one company and month.
 */
#[State(table: 'cw_ss_company_months')]
final readonly class CompanyMonthUsageState
{
    /**
     * @param list<Charge> $charges
     */
    public function __construct(
        #[StateId(column: 'company_month_id')]
        public string $companyMonthId,
        #[StateCollection(itemClass: Charge::class)]
        public array $charges = [],
    ) {}

    public function usedMinutes(?string $ignoringBookingId = null): int
    {
        $used = 0;
        foreach ($this->charges as $charge) {
            if ($charge->bookingId !== $ignoringBookingId) {
                $used += $charge->minutes;
            }
        }

        return $used;
    }

    public function charging(string $bookingId, int $minutes): self
    {
        return new self($this->companyMonthId, [...$this->refunding($bookingId)->charges, new Charge($bookingId, $minutes)]);
    }

    public function refunding(string $bookingId): self
    {
        return new self($this->companyMonthId, array_values(array_filter(
            $this->charges,
            static fn (Charge $charge): bool => $charge->bookingId !== $bookingId,
        )));
    }
}
