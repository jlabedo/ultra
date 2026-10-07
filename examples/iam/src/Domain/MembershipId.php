<?php

declare(strict_types=1);

namespace IamExample\Domain;

final readonly class MembershipId
{
    public function __construct(
        public string $tenantId,
        public string $groupId,
        public string $subjectId,
    ) {
        new LocalId($tenantId, $groupId);
        new LocalId($tenantId, $subjectId);
    }
}
