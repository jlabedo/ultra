<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Tests\Example\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

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
