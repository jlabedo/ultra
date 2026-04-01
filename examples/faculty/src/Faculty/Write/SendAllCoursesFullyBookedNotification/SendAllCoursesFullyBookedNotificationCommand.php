<?php

declare(strict_types=1);

namespace App\Faculty\Write\SendAllCoursesFullyBookedNotification;

use App\Faculty\Event\AllCoursesFullyBookedNotificationSent;
use App\Faculty\State\FacultyAdminNotificationState;
use App\Faculty\State\FacultyCourseAvailabilityState;
use App\Faculty\Support\FacultyIds;
use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\Mediator\Attribute\Handles;

final readonly class SendAllCoursesFullyBookedNotificationCommand
{
    public function __construct(
        public string $facultyId = FacultyIds::DEFAULT,
    ) {}

    /**
     * @return list<AllCoursesFullyBookedNotificationSent>
     */
    #[Handles]
    #[DecisionHandler]
    public function decide(
        FacultyCourseAvailabilityState $faculty,
        FacultyAdminNotificationState $notifications,
    ): array {
        if (
            ! $faculty->hasCourses()
            || ! $faculty->allCoursesFullyBooked()
            || $notifications->hasSentAllCoursesFullyBookedNotification()
        ) {
            return [];
        }

        return [
            new AllCoursesFullyBookedNotificationSent($this->facultyId),
        ];
    }
}
