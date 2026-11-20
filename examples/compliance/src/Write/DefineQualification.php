<?php

declare(strict_types=1);

namespace ComplianceExample\Write;

use ComplianceExample\Event\QualificationDefined;
use ComplianceExample\Requirements;
use ComplianceExample\State\QualificationState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

final readonly class DefineQualification
{
    public function __construct(
        public string $account,
        public string $qualification,
        public Requirements $requirements,
    ) {
        CommandInput::requireValues($account, $qualification);
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(QualificationState $state): QualificationDefined
    {
        if ($state->defined) {
            throw new \DomainException('Use a new identifier to define another version of this qualification.');
        }

        return new QualificationDefined($this->account, $this->qualification, $this->requirements->groups);
    }
}
