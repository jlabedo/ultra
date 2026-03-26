<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure\Notification;

final class LoggingNotificationService implements NotificationService
{
    public function send(Notification $notification): void
    {
        error_log(sprintf(
            '[faculty] notification to %s: %s',
            $notification->recipientId,
            $notification->message,
        ));
    }
}
