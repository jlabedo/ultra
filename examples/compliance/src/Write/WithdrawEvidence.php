<?php

declare(strict_types=1);

namespace ComplianceExample\Write;

use ComplianceExample\Event\EvidenceWithdrawn;
use ComplianceExample\State\EvidenceState;
use ComplianceExample\State\EvidenceStatus;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

final readonly class WithdrawEvidence
{
    public function __construct(
        public string $account,
        public string $subject,
        public string $evidence,
    ) {
        CommandInput::requireValues($account, $subject, $evidence);
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(EvidenceState $state): EvidenceWithdrawn
    {
        if (EvidenceStatus::Active !== $state->status) {
            throw new \DomainException('Only active evidence can be withdrawn.');
        }

        return new EvidenceWithdrawn($this->account, $this->subject, $this->evidence);
    }
}
