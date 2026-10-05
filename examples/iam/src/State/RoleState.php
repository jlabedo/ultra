<?php

declare(strict_types=1);

namespace IamExample\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateCollection;
use IamExample\Domain\LocalId;

#[State(table: 'iam_roles')]
final readonly class RoleState
{
    /** @param list<PermissionState> $permissions */
    public function __construct(
        #[StateId(columns: ['tenantId' => 'tenant_id', 'localId' => 'role_id'])]
        public LocalId $id,
        public string $name,
        #[StateCollection(itemClass: PermissionState::class)]
        public array $permissions,
        public bool $active = true,
    ) {}
}
