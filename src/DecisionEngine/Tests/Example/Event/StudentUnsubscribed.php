<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Event;

use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\MessageHandling\Attribute\Event;

#[Event('student.unsubscribed')]
readonly class StudentUnsubscribed
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}
