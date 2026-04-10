<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('student.enrolled')]
readonly class StudentEnrolled
{
    public function __construct(
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}
