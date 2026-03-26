<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure\Notification;

final readonly class Notification implements \JsonSerializable
{
    public function __construct(
        public string $recipientId,
        public string $message,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'recipientId' => $this->recipientId,
            'message' => $this->message,
        ];
    }
}
