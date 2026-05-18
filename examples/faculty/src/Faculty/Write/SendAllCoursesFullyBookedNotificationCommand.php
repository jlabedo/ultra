<?php

declare(strict_types=1);

namespace App\Faculty\Write;

use App\Faculty\Event\AllCoursesFullyBookedNotificationSent;
use App\Faculty\State\FacultyAdminNotificationState;
use App\Faculty\State\FacultyCourseAvailabilityState;
use App\Faculty\Support\FacultyIds;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\MessageHandling\Attribute\SelfHandler;

final readonly class SendAllCoursesFullyBookedNotificationCommand
{
    public function __construct(
        public string $facultyId = FacultyIds::DEFAULT,
    ) {}

    /**
     * @return list<AllCoursesFullyBookedNotificationSent>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        FacultyCourseAvailabilityState $faculty,
        FacultyAdminNotificationState $notifications,
    ): array {
        if (
            !$faculty->hasCourses()
            || !$faculty->allCoursesFullyBooked()
            || $notifications->hasSentAllCoursesFullyBookedNotification()
        ) {
            return [];
        }

        return [
            new AllCoursesFullyBookedNotificationSent($this->facultyId),
        ];
    }
}
