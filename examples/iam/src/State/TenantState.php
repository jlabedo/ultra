<?php

declare(strict_types=1);

namespace IamExample\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'iam_tenants')]
final readonly class TenantState
{
    public function __construct(
        #[StateId(column: 'tenant_id')]
        public string $tenantId,
    ) {}
}
