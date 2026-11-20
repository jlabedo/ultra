<?php

declare(strict_types=1);

namespace ComplianceExample;

/** Each group requires at least one of its document types. */
final readonly class Requirements
{
    /** @var non-empty-list<non-empty-list<non-empty-string>> */
    public array $groups;

    /** @param array<array-key, mixed> $groups */
    public function __construct(array $groups)
    {
        if ([] === $groups || !array_is_list($groups)) {
            throw new \InvalidArgumentException('Requirements must be a non-empty list of groups.');
        }

        $validated = [];
        foreach ($groups as $group) {
            if (!is_array($group) || [] === $group || !array_is_list($group)) {
                throw new \InvalidArgumentException('Each group must be a non-empty list of document types.');
            }

            $types = [];
            foreach ($group as $type) {
                if (!is_string($type) || '' === trim($type) || str_contains($type, "\0")) {
                    throw new \InvalidArgumentException('Document types must be non-empty strings without NUL.');
                }
                $types[] = $type;
            }
            $validated[] = $types;
        }
        $this->groups = $validated;
    }
}
