<?php

declare(strict_types=1);

namespace App\Faculty\Event;

use App\Faculty\Support\EventTypes;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event(EventTypes::ALL_COURSES_FULLY_BOOKED_NOTIFICATION_SENT)]
final readonly class AllCoursesFullyBookedNotificationSent
{
    public function __construct(
        #[DomainIdentifier('faculty')]
        public string $facultyId,
    ) {}
}
