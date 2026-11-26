<?php

declare(strict_types=1);

namespace GettingStarted\Domain;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'tutorial_contacts')]
final readonly class Contact
{
    public function __construct(
        #[StateId(column: 'id')]
        public string $id,
        #[StateColumn('name')]
        public string $name,
    ) {}
}
