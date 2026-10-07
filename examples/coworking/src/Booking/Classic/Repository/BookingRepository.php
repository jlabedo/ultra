<?php

declare(strict_types=1);

namespace App\Booking\Classic\Repository;

use App\Booking\Classic\Entity\Booking;
use App\Booking\Classic\Entity\Company;
use App\Booking\Classic\Entity\Room;
use App\Booking\Domain\Slot;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BookingRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function hasOverlap(Room $room, Slot $slot, ?string $ignoringBookingId = null): bool
    {
        return (
            [] !== $this->entityManager
                ->createQuery(
                    'SELECT b.id FROM '
                    . Booking::class
                    . ' b
             WHERE b.room = :room AND b.status = :confirmed AND b.start < :end AND b.end > :start AND b.id != :ignoring',
                )
                ->setParameters([
                    'room' => $room,
                    'confirmed' => Booking::CONFIRMED,
                    'start' => $slot->start,
                    'end' => $slot->end,
                    'ignoring' => $ignoringBookingId ?? '',
                ])
                ->setMaxResults(1)
                ->getResult()
        );
    }

    public function usedMinutes(Company $company, string $month, ?string $ignoringBookingId = null): int
    {
        $from = new \DateTimeImmutable($month . '-01 00:00 UTC');

        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (end_at - start_at)) / 60), 0) FROM cw_orm_bookings
             WHERE company_id = ? AND charged AND start_at >= ? AND start_at < ? AND id != ?',
            [$company->id, $from->format('c'), $from->modify('+1 month')->format('c'), $ignoringBookingId ?? ''],
        );
    }

    /**
     * @return list<Booking>
     */
    public function confirmedOnDay(Room $room, string $day): array
    {
        $from = new \DateTimeImmutable($day . ' 00:00 UTC');

        return $this->entityManager
            ->createQuery(
                'SELECT b FROM '
                . Booking::class
                . ' b
             WHERE b.room = :room AND b.status = :confirmed AND b.start >= :from AND b.start < :to ORDER BY b.id',
            )
            ->setParameters([
                'room' => $room,
                'confirmed' => Booking::CONFIRMED,
                'from' => $from,
                'to' => $from->modify('+1 day'),
            ])
            ->getResult();
    }
}
