<?php

declare(strict_types=1);

namespace App\Faculty\Automation\AllCoursesFullyBookedNotifier;

use App\Faculty\Event\AllCoursesFullyBookedNotificationSent;
use App\Faculty\Infrastructure\Notification\Notification;
use App\Faculty\Infrastructure\Notification\NotificationService;
use App\Faculty\Support\FacultyIds;
use Ultra\EventBus\Attribute\EventHandler;

final readonly class WhenAllCoursesFullyBookedThenSendNotification
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    #[EventHandler]
    public function react(AllCoursesFullyBookedNotificationSent $event): void
    {
        $this->notificationService->send(new Notification(
            FacultyIds::ADMIN_RECIPIENT,
            'All courses are fully booked now.',
        ));
    }
}
