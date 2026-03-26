<?php

declare(strict_types=1);

namespace App\Faculty\State;

use App\Faculty\Event\AllCoursesFullyBookedNotificationSent;
use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\DecisionEngine\ReducerTrait;

final class FacultyAdminNotificationState
{
    use ReducerTrait;

    #[DomainIdentifier('faculty')]
    private string $facultyId = '';

    private bool $sentAllCoursesFullyBookedNotification = false;

    public function hasSentAllCoursesFullyBookedNotification(): bool
    {
        return $this->sentAllCoursesFullyBookedNotification;
    }

    private function onAllCoursesFullyBookedNotificationSent(AllCoursesFullyBookedNotificationSent $event): void
    {
        $this->facultyId = $event->facultyId;
        $this->sentAllCoursesFullyBookedNotification = true;
    }
}
