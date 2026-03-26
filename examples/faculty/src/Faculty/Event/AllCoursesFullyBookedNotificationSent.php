<?php

declare(strict_types=1);

namespace App\Faculty\Event;

use App\Faculty\Support\EventTypes;
use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\MessageHandling\Attribute\Event;

#[Event(EventTypes::ALL_COURSES_FULLY_BOOKED_NOTIFICATION_SENT)]
final readonly class AllCoursesFullyBookedNotificationSent
{
    public function __construct(
        #[DomainIdentifier('faculty')]
        public string $facultyId,
    ) {}
}
