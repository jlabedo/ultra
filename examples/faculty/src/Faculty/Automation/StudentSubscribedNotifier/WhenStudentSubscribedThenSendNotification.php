<?php

declare(strict_types=1);

namespace App\Faculty\Automation\StudentSubscribedNotifier;

use App\Faculty\Event\StudentSubscribedToCourse;
use App\Faculty\Infrastructure\Notification\Notification;
use App\Faculty\Infrastructure\Notification\NotificationService;
use Ultra\EventBus\Attribute\EventHandler;

final readonly class WhenStudentSubscribedThenSendNotification
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    #[EventHandler]
    public function react(StudentSubscribedToCourse $event): void
    {
        $this->notificationService->send(new Notification(
            $event->studentId,
            sprintf('You have subscribed to course %s.', $event->courseId),
        ));
    }
}
