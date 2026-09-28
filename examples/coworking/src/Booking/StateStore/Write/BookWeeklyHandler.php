<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\Domain\BookingIds;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\MediatorInterface;

final readonly class BookWeeklyHandler
{
    public function __construct(private MediatorInterface $mediator) {}

    #[Handles]
    public function handle(BookWeekly $command): void
    {
        for ($week = 0; $week < $command->weeks; ++$week) {
            $this->mediator->send(new BookRoom(
                BookingIds::occurrence($command->seriesId, $week),
                $command->roomId,
                $command->memberId,
                $command->firstSlot->shiftedByWeeks($week),
            ));
        }
    }
}
