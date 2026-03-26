<?php

declare(strict_types=1);

namespace App\Faculty\Event;

use App\Faculty\Support\EventTypes;
use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\MessageHandling\Attribute\Event;

#[Event(EventTypes::STUDENT_UNSUBSCRIBED_FROM_COURSE)]
final readonly class StudentUnsubscribedFromCourse
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
