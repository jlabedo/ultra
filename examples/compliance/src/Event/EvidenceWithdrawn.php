<?php

declare(strict_types=1);

namespace ComplianceExample\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event(self::TYPE)]
final readonly class EvidenceWithdrawn
{
    public const string TYPE = 'compliance.evidence.withdrawn';

    public function __construct(
        #[DomainIdentifier('account')]
        public string $account,
        #[DomainIdentifier('subject')]
        public string $subject,
        #[DomainIdentifier('evidence')]
        public string $evidence,
    ) {}
}
