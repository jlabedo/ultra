<?php

declare(strict_types=1);

namespace IamExample\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Attribute\StateColumn;
use IamExample\Domain\BindingId;

#[State(table: 'iam_bindings')]
final readonly class BindingState
{
    public function __construct(
        #[StateId(columns: [
            'tenantId' => 'tenant_id', 'principalType' => 'principal_type',
            'principalId' => 'principal_id', 'roleId' => 'role_id',
            'scopeType' => 'scope_type', 'scopeId' => 'scope_id',
        ])]
        public BindingId $id,
        public bool $active,
        #[StateColumn('expires_at')]
        public ?int $expiresAt = null,
    ) {}
}
