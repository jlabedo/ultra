<?php

declare(strict_types=1);

namespace IamExample\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Attribute\StateColumn;
use IamExample\Domain\LocalId;

#[State(table: 'iam_groups')]
final readonly class GroupState
{
    public function __construct(
        #[StateId(columns: ['tenantId' => 'tenant_id', 'localId' => 'group_id'])]
        public LocalId $id,
        public bool $active = true,
    ) {}
}
