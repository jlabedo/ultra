<?php

declare(strict_types=1);

namespace IamExample\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Attribute\StateColumn;

#[State(table: 'iam_audit')]
final readonly class AuditEntry
{
    public function __construct(
        #[StateId(column: 'entry_id')] public string $entryId,
        #[StateColumn('tenant_id')] public string $tenantId,
        #[StateColumn('actor_id')] public string $actorId,
        public string $operation,
        public string $target,
        #[StateColumn('occurred_at')] public int $occurredAt,
        public string $snapshot,
    ) {}
}
