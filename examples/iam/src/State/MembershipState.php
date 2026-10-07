<?php

declare(strict_types=1);

namespace IamExample\State;

use IamExample\Domain\MembershipId;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'iam_memberships')]
final readonly class MembershipState
{
    public function __construct(
        #[StateId(columns: ['tenantId' => 'tenant_id', 'groupId' => 'group_id', 'subjectId' => 'subject_id'])]
        public MembershipId $id,
        public bool $active,
    ) {}
}
