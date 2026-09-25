<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Write;

use App\Booking\Domain\Violations;
use App\Booking\EventSourced\Event\CompanyPlanChanged;
use App\Booking\EventSourced\State\CompanyState;
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
    public function decide(CompanyState $company): CompanyPlanChanged
    {
        Violations::throwIfAny([
            !$company->exists ? new Violation('company.not_found', 'Company does not exist.') : null,
        ]);

        return new CompanyPlanChanged($this->companyId, $this->monthlyMinutes);
    }
}
