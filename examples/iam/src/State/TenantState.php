<?php

declare(strict_types=1);

namespace IamExample\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Attribute\StateColumn;

#[State(table: 'iam_tenants')]
final readonly class TenantState
{
    public function __construct(#[StateId(column: 'tenant_id')] public string $tenantId) {}
}
