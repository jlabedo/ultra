<?php

declare(strict_types=1);

namespace App\Faculty\Automation\AllCoursesFullyBookedNotifier;

use App\Faculty\Event\CourseCapacityChanged;
use App\Faculty\Event\CourseCreated;
use App\Faculty\Event\StudentSubscribedToCourse;
use App\Faculty\Event\StudentUnsubscribedFromCourse;
use App\Faculty\Write\SendAllCoursesFullyBookedNotification\SendAllCoursesFullyBookedNotificationCommand;
use Ultra\EventBus\Attribute\EventHandler;
use Ultra\Mediator\MediatorInterface;

final readonly class WhenCourseAvailabilityChangesThenMaybeSendNotification
{
    public function __construct(
        private MediatorInterface $mediator,
    ) {}

    #[EventHandler]
    public function onCourseCreated(CourseCreated $event): void
    {
        $this->maybeSendNotification();
    }

    #[EventHandler]
    public function onCourseCapacityChanged(CourseCapacityChanged $event): void
    {
        $this->maybeSendNotification();
    }

    #[EventHandler]
    public function onStudentSubscribed(StudentSubscribedToCourse $event): void
    {
        $this->maybeSendNotification();
    }

    #[EventHandler]
    public function onStudentUnsubscribed(StudentUnsubscribedFromCourse $event): void
    {
        $this->maybeSendNotification();
    }

    private function maybeSendNotification(): void
    {
        $this->mediator->send(new SendAllCoursesFullyBookedNotificationCommand());
    }
}
