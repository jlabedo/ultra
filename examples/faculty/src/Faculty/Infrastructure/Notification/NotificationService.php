<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure\Notification;

interface NotificationService
{
    public function send(Notification $notification): void;
}
