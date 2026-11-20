<?php

declare(strict_types=1);

namespace ComplianceExample\Write;

use ComplianceExample\Event\EvidenceProvided;
use ComplianceExample\State\EvidenceState;
use ComplianceExample\State\EvidenceStatus;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

final readonly class ProvideEvidence
{
    public function __construct(
        public string $account,
        public string $subject,
        public string $evidence,
        public string $documentType,
    ) {
        CommandInput::requireValues($account, $subject, $evidence, $documentType);
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(EvidenceState $state): EvidenceProvided
    {
        if (EvidenceStatus::Unused !== $state->status) {
            throw new \DomainException('This subject has already used the evidence identifier.');
        }

        return new EvidenceProvided($this->account, $this->subject, $this->evidence, $this->documentType);
    }
}
