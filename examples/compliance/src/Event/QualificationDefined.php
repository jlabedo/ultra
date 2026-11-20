<?php

declare(strict_types=1);

namespace ComplianceExample\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event(self::TYPE)]
final readonly class QualificationDefined
{
    public const string TYPE = 'compliance.qualification.defined';

    /** @param non-empty-list<non-empty-list<non-empty-string>> $requirements */
    public function __construct(
        #[DomainIdentifier('account')]
        public string $account,
        #[DomainIdentifier('qualification')]
        public string $qualification,
        public array $requirements,
    ) {}
}
