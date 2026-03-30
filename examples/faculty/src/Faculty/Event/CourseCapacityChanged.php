<?php

declare(strict_types=1);

namespace App\Faculty\Event;

use App\Faculty\Support\EventTypes;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event(EventTypes::COURSE_CAPACITY_CHANGED)]
final readonly class CourseCapacityChanged
{
    public function __construct(
        #[DomainIdentifier('faculty')]
        public string $facultyId,
        #[DomainIdentifier('course')]
        public string $courseId,
        public int $capacity,
    ) {}
}
