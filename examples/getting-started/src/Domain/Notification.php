<?php

declare(strict_types=1);

namespace GettingStarted\Domain;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'tutorial_notifications')]
final readonly class Notification
{
    public function __construct(
        #[StateId(column: 'id')]
        public string $id,
        #[StateColumn('body')]
        public string $body,
    ) {}
}
