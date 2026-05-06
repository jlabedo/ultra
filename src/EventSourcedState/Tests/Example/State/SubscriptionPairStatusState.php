<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Tests\Example\State;

use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;
use Ultra\EventSourcedState\Tests\Example\Event\SubscriptionPairConfirmed;

#[EventSourcedEntity(tags: ['course', 'student'])]
final class SubscriptionPairStatusState
{
    /** @var list<string> */
    public array $statuses = [];

    #[EventSourcingHandler]
    #[MatchTag('course', 'student')]
    public function onPairConfirmed(SubscriptionPairConfirmed $event): void
    {
        $this->statuses[] = $event->status;
    }
}
