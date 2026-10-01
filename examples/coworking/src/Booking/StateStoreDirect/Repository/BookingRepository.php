<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect\Repository;

use App\Booking\Domain\Slot;
use App\Booking\StateStoreDirect\State\Booking;
use App\Booking\StateStoreDirect\State\Company;
use App\Booking\StateStoreDirect\State\Room;
use Doctrine\DBAL\Connection;
use Ultra\StateStore\StateStore;
use Ultra\UnitOfWork\UnitOfWork;

/** SQL expectations observe committed rows; this local overlay supplies read-your-own-writes until clear(). */
final class BookingRepository implements UnitOfWork
{
    /** @var array<string, array{original: ?Booking, current: Booking}> */
    private array $pending = [];

    public function __construct(private Connection $connection, private StateStore $states) {}

    public function save(Booking $booking, ?Booking $original = null): void
    {
        $this->pending[$booking->id] = [
            'original' => array_key_exists($booking->id, $this->pending) ? $this->pending[$booking->id]['original'] : $original,
            'current' => $booking,
        ];
        $this->states->save($booking);
    }

    public function emailTaken(string $email): bool
    {
        return false !== $this->connection->fetchOne('SELECT id FROM cw_direct_members WHERE email = ?', [$email]);
    }

    public function hasOverlap(Room $room, Slot $slot, ?string $ignoringBookingId = null): bool
    {
        $ids = $this->states->observeQuery(fn (): array => $this->connection->fetchFirstColumn(
            'SELECT id FROM cw_direct_bookings WHERE room_id = ? AND status = ? AND start_at < ? AND end_at > ? AND id != ? ORDER BY id',
            [$room->id, Booking::CONFIRMED, $slot->end->format('c'), $slot->start->format('c'), $ignoringBookingId ?? ''],
        ), 'overlaps for room '.$room->id);
        if ([] !== array_diff($ids, array_keys($this->pending))) {
            return true;
        }
        foreach ($this->pending as ['current' => $booking]) {
            if ($booking->id !== $ignoringBookingId && $booking->roomId === $room->id && Booking::CONFIRMED === $booking->status && $booking->slot()->overlaps($slot)) {
                return true;
            }
        }

        return false;
    }

    public function usedMinutes(Company $company, string $month, ?string $ignoringBookingId = null): int
    {
        $from = new \DateTimeImmutable($month.'-01 00:00 UTC');

        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (end_at - start_at)) / 60), 0) FROM cw_direct_bookings
             WHERE company_id = ? AND charged AND start_at >= ? AND start_at < ? AND id != ?',
            [$company->id, $from->format('c'), $from->modify('+1 month')->format('c'), $ignoringBookingId ?? ''],
        );
    }

    public function observeUsedMinutes(Company $company, string $month, ?string $ignoringBookingId = null): int
    {
        $minutes = $this->states->observeQuery(fn (): int => $this->usedMinutes($company, $month, $ignoringBookingId), 'quota for company '.$company->id.' in '.$month);
        foreach ($this->pending as ['original' => $original, 'current' => $current]) {
            foreach ([[$original, -1], [$current, 1]] as [$booking, $sign]) {
                if (null !== $booking && $booking->id !== $ignoringBookingId && $booking->companyId === $company->id && $booking->charged && $booking->slot()->month() === $month) {
                    $minutes += $sign * $booking->slot()->minutes();
                }
            }
        }

        return $minutes;
    }

    /** @return list<string> */
    public function bookingIdsOnDay(Room $room, string $day): array
    {
        $from = new \DateTimeImmutable($day.' 00:00 UTC');

        return $this->connection->fetchFirstColumn(
            'SELECT id FROM cw_direct_bookings WHERE room_id = ? AND status = ? AND start_at >= ? AND start_at < ? ORDER BY id',
            [$room->id, Booking::CONFIRMED, $from->format('c'), $from->modify('+1 day')->format('c')],
        );
    }

    /** @return list<Booking> */
    public function observeConfirmedOnDay(Room $room, string $day): array
    {
        $ids = $this->states->observeQuery(fn (): array => $this->bookingIdsOnDay($room, $day), 'bookings for room '.$room->id.' on '.$day);
        $ids = array_diff($ids, array_keys($this->pending));
        foreach ($this->pending as ['current' => $booking]) {
            if ($booking->roomId === $room->id && Booking::CONFIRMED === $booking->status && $booking->slot()->day() === $day) {
                $ids[] = $booking->id;
            }
        }
        sort($ids, SORT_STRING);

        return array_map(fn (string $id): Booking => $this->states->observe(Booking::class, $id), $ids);
    }

    public function hasPendingChanges(): bool
    {
        // StateStore owns the write plan. This participant only shares its lifetime for the query overlay.
        return false;
    }

    public function flush(): void {}

    public function clear(): void
    {
        $this->pending = [];
    }
}
