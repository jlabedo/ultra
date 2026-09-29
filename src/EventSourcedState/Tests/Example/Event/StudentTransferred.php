<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Tests\Example\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('student.transferred')]
readonly class StudentTransferred
{
    public function __construct(
        #[DomainIdentifier('student')]
        public string $studentId,
        #[DomainIdentifier('previousCourse')]
        public string $previousCourseId,
        #[DomainIdentifier('course')]
        public string $courseId,
    ) {}
}
