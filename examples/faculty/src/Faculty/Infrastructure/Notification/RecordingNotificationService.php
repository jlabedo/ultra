<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure\Notification;

final class RecordingNotificationService implements NotificationService
{
    /** @var list<Notification> */
    private array $sentNotifications = [];

    public function __construct(
        private readonly LoggingNotificationService $delegate,
    ) {}

    public function send(Notification $notification): void
    {
        $this->delegate->send($notification);
        $this->sentNotifications[] = $notification;
    }

    /**
     * @return list<Notification>
     */
    public function sent(): array
    {
        return $this->sentNotifications;
    }
}
