<?php

declare(strict_types=1);

namespace App\Faculty\Event;

use App\Faculty\Support\EventTypes;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event(EventTypes::STUDENT_SUBSCRIBED_TO_COURSE)]
final readonly class StudentSubscribedToCourse
{
    public function __construct(
        #[DomainIdentifier('faculty')]
        public string $facultyId,
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}
