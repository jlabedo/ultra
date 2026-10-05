<?php

declare(strict_types=1);

namespace IamExample\Domain;

final readonly class LocalId
{
    public function __construct(public string $tenantId, public string $localId)
    {
        if ('' === trim($tenantId) || '' === trim($localId)) {
            throw new \InvalidArgumentException('Tenant and local identifiers are required.');
        }
    }
}
