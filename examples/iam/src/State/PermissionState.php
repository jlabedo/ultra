<?php

declare(strict_types=1);

namespace IamExample\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Attribute\StateColumn;

#[State(table: 'iam_role_permissions')]
final readonly class PermissionState
{
    public function __construct(#[StateId(column: 'permission')] public string $permission) {}
}
