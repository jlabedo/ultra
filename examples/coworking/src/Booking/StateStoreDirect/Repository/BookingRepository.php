<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect\Repository;

use App\Booking\Domain\Slot;
use App\Booking\StateStoreDirect\State\Booking;
use App\Booking\StateStoreDirect\State\Company;
use App\Booking\StateStoreDirect\State\Room;
use Doctrine\DBAL\Connection;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\Attribute\DefineQueryGuard;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\QueryGuardDefinition;

/** Guarded queries read committed rows before affected writes are prepared. */
final readonly class BookingRepository
{
    public function __construct(
        private Connection $connection,
        private StateStore $states,
        #[DefineQueryGuard('company_usage', static function (QueryGuardDefinition $definition): void {
            $definition
                ->watch(Booking::class, keyBy: 'company_id')
                ->whenColumnsChange('charged', 'start_at', 'end_at');
        })]
        private QueryGuard $companyUsage,
        #[DefineQueryGuard('room_bookings', static function (QueryGuardDefinition $definition): void {
            $definition
                ->watch(Booking::class, keyBy: 'room_id')
                ->whenColumnsChange('status', 'start_at', 'end_at');
        })]
        private QueryGuard $roomBookings,
    ) {}

    public function emailTaken(string $email): bool
    {
        return false !== $this->connection->fetchOne('SELECT id FROM cw_direct_members WHERE email = ?', [$email]);
    }

    public function hasOverlap(Room $room, Slot $slot, ?string $ignoringBookingId = null): bool
    {
        $ids = $this->roomBookings->observe($room->id, fn (): array => $this->connection->fetchFirstColumn(
            'SELECT id FROM cw_direct_bookings WHERE room_id = ? AND status = ? AND start_at < ? AND end_at > ? AND id != ? ORDER BY id',
            [$room->id, Booking::CONFIRMED, $slot->end->format('c'), $slot->start->format('c'), $ignoringBookingId ?? ''],
        ));
        return [] !== $ids;
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
        return $this->companyUsage->observe($company->id, fn (): int => $this->usedMinutes($company, $month, $ignoringBookingId));
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
        $ids = $this->roomBookings->observe($room->id, fn (): array => $this->bookingIdsOnDay($room, $day));
        return array_map(fn (string $id): Booking => $this->states->observe(Booking::class, $id), $ids);
    }
}
