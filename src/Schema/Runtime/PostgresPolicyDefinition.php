<?php

declare(strict_types=1);

namespace Ultra\Schema;

final readonly class PostgresPolicyDefinition
{
    /** @var list<string> PostgreSQL role names. An empty list means PUBLIC. */
    public array $roles;

    /** @param list<string> $roles */
    public function __construct(
        public PolicyCommand $command = PolicyCommand::All,
        array $roles = [],
        public ?string $using = null,
        public ?string $withCheck = null,
        public PolicyMode $mode = PolicyMode::Permissive,
    ) {
        foreach ($roles as $role) {
            if ('' === trim($role) || str_contains($role, "\0")) {
                throw new \InvalidArgumentException('A policy role name cannot be blank or contain a NUL byte.');
            }
        }
        if (null !== $using && ('' === trim($using) || PolicyCommand::Insert === $command)) {
            throw new \InvalidArgumentException(sprintf('Invalid USING expression for %s policy.', $command->value));
        }
        if (
            null !== $withCheck
            && ('' === trim($withCheck) || in_array($command, [PolicyCommand::Select, PolicyCommand::Delete], true))
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid WITH CHECK expression for %s policy.',
                $command->value,
            ));
        }

        $roles = array_unique($roles);
        sort($roles, SORT_STRING);
        $this->roles = $roles;
    }

    public function equivalentTo(?self $other): bool
    {
        return (
            null !== $other
            && $this->command === $other->command
            && $this->roles === $other->roles
            && $this->using === $other->using
            && $this->withCheck === $other->withCheck
            && $this->mode === $other->mode
        );
    }
}
