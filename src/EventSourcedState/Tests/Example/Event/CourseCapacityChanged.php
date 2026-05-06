<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Tests\Example\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('course.capacity_changed')]
readonly class CourseCapacityChanged
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        public int $maxCapacity,
    ) {}
}
