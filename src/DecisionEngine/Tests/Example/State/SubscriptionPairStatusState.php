<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\State;

use Ultra\DecisionEngine\Attribute\EventSourcedEntity;
use Ultra\DecisionEngine\Attribute\EventSourcingHandler;
use Ultra\DecisionEngine\Attribute\MatchTag;
use Ultra\DecisionEngine\Tests\Example\Event\SubscriptionPairConfirmed;

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
