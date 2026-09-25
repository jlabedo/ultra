<?php

declare(strict_types=1);

namespace App\Booking\Classic\Entity;

use App\Booking\Domain\Slot;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cw_orm_bookings')]
#[ORM\Index(columns: ['room_id', 'start_at'])]
#[ORM\Index(columns: ['company_id', 'start_at'])]
class Booking
{
    public const string CONFIRMED = 'confirmed';
    public const string CANCELLED = 'cancelled';

    #[ORM\Column]
    public private(set) string $status = self::CONFIRMED;

    /** Late cancellations stay charged to the company. */
    #[ORM\Column]
    public private(set) bool $charged = true;

    #[ORM\Column(name: 'start_at')]
    public private(set) \DateTimeImmutable $start;

    #[ORM\Column(name: 'end_at')]
    public private(set) \DateTimeImmutable $end;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public private(set) string $id,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) Room $room,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) Member $member,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) Company $company,
        Slot $slot,
    ) {
        $this->start = $slot->start;
        $this->end = $slot->end;
    }

    public function slot(): Slot
    {
        return new Slot($this->start, $this->end);
    }

    public function isCancelled(): bool
    {
        return self::CANCELLED === $this->status;
    }

    public function cancel(bool $refunded): void
    {
        $this->status = self::CANCELLED;
        $this->charged = !$refunded;
    }

    public function moveTo(Room $room, Slot $slot): void
    {
        $this->room = $room;
        $this->start = $slot->start;
        $this->end = $slot->end;
    }
}
