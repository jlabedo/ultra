<?php

declare(strict_types=1);

namespace IamExample\State;

use IamExample\Domain\LocalId;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'iam_subjects')]
final readonly class SubjectState
{
    public function __construct(
        #[StateId(columns: ['tenantId' => 'tenant_id', 'localId' => 'subject_id'])]
        public LocalId $id,
        public bool $active = true,
    ) {}
}
