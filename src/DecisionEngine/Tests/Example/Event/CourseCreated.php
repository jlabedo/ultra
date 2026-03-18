<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Event;

use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\MessageHandling\Attribute\Event;

#[Event('course.created')]
readonly class CourseCreated
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        public string $name,
        public int $maxCapacity,
    ) {}
}
