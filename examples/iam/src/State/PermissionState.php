<?php

declare(strict_types=1);

namespace IamExample\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'iam_role_permissions')]
final readonly class PermissionState
{
    public function __construct(
        #[StateId(column: 'permission')]
        public string $permission,
    ) {}
}
