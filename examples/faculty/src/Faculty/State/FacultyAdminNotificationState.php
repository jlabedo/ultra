<?php

declare(strict_types=1);

namespace App\Faculty\State;

use App\Faculty\Event\AllCoursesFullyBookedNotificationSent;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['faculty'])]
final class FacultyAdminNotificationState
{
    private string $facultyId = '';

    private bool $sentAllCoursesFullyBookedNotification = false;

    public function hasSentAllCoursesFullyBookedNotification(): bool
    {
        return $this->sentAllCoursesFullyBookedNotification;
    }

    #[EventSourcingHandler]
    public function onAllCoursesFullyBookedNotificationSent(AllCoursesFullyBookedNotificationSent $event): void
    {
        $this->facultyId = $event->facultyId;
        $this->sentAllCoursesFullyBookedNotification = true;
    }
}
