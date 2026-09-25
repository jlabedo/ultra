<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\Domain\Violations;
use App\Booking\StateStore\State\CompanyState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;

final readonly class ChangeCompanyPlan
{
    public function __construct(
        public string $companyId,
        public int $monthlyMinutes,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(?CompanyState $company): CompanyState
    {
        Violations::throwIfAny([
            null === $company ? new Violation('company.not_found', 'Company does not exist.') : null,
        ]);

        return $company->withMonthlyMinutes($this->monthlyMinutes);
    }
}
