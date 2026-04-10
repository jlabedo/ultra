<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('subscription.pair_confirmed')]
readonly class SubscriptionPairConfirmed
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
        public string $status,
    ) {}
}
