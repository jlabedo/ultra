<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Write;

use App\Booking\Domain\Violations;
use App\Booking\EventSourced\Event\CompanyRegistered;
use App\Booking\EventSourced\State\CompanyState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;

final readonly class RegisterCompany
{
    public function __construct(
        public string $companyId,
        public string $name,
        public int $monthlyMinutes,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CompanyState $company): CompanyRegistered
    {
        Violations::throwIfAny([
            $company->exists ? new Violation('company.already_registered', 'Company already exists.') : null,
        ]);

        return new CompanyRegistered($this->companyId, $this->name, $this->monthlyMinutes);
    }
}
