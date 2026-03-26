<?php

declare(strict_types=1);

namespace App\Faculty\Event;

use App\Faculty\Support\EventTypes;
use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\MessageHandling\Attribute\Event;

#[Event(EventTypes::COURSE_CREATED)]
final readonly class CourseCreated
{
    public function __construct(
        #[DomainIdentifier('faculty')]
        public string $facultyId,
        #[DomainIdentifier('course')]
        public string $courseId,
        public string $name,
        public int $capacity,
    ) {}
}
