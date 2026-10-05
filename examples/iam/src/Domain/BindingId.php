<?php

declare(strict_types=1);

namespace IamExample\Domain;

final readonly class BindingId
{
    public function __construct(
        public string $tenantId,
        public string $principalType,
        public string $principalId,
        public string $roleId,
        public string $scopeType,
        public string $scopeId,
    ) {
        new LocalId($tenantId, $principalId);
        new LocalId($tenantId, $roleId);
        if (!in_array($principalType, ['subject', 'group'], true)) {
            throw new \InvalidArgumentException('Unknown principal type.');
        }
        if ('tenant' === $scopeType && '*' === $scopeId) {
            return;
        }
        Scope::resource($scopeType, $scopeId);
    }

    public static function forPrincipal(LocalId $principal, string $type, string $roleId, Scope $scope): self
    {
        return new self($principal->tenantId, $type, $principal->localId, $roleId, $scope->type, $scope->id);
    }
}
