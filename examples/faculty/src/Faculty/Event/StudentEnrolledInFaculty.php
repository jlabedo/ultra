<?php

declare(strict_types=1);

namespace App\Faculty\Event;

use App\Faculty\Support\EventTypes;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event(EventTypes::STUDENT_ENROLLED_IN_FACULTY)]
final readonly class StudentEnrolledInFaculty
{
    public function __construct(
        #[DomainIdentifier('faculty')]
        public string $facultyId,
        #[DomainIdentifier('student')]
        public string $studentId,
        public string $firstName,
        public string $lastName,
    ) {}
}
