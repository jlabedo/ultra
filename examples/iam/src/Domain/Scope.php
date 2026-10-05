<?php

declare(strict_types=1);

namespace IamExample\Domain;

final readonly class Scope
{
    private function __construct(public string $type, public string $id) {}

    public static function tenant(): self
    {
        return new self('tenant', '*');
    }

    public static function resource(string $type, string $id): self
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $type) || 'tenant' === $type || '' === trim($id) || '*' === $id) {
            throw new \InvalidArgumentException('An exact resource type and identifier are required.');
        }

        return new self($type, $id);
    }
}
