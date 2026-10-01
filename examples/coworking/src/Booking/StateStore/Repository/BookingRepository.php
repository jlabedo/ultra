<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Repository;

use App\Booking\Domain\Slot;
use App\Booking\StateStore\State\BookingState;
use App\Booking\StateStore\State\CompanyState;
use App\Booking\StateStore\State\RoomState;
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
        #[DefineQueryGuard('state_store_company_usage', static function (QueryGuardDefinition $definition): void {
            $definition
                ->watch(BookingState::class, keyBy: 'company_id')
                ->whenColumnsChange('charged', 'start_at', 'end_at');
        })]
        private QueryGuard $companyUsage,
        #[DefineQueryGuard('state_store_room_bookings', static function (QueryGuardDefinition $definition): void {
            $definition
                ->watch(BookingState::class, keyBy: 'room_id')
                ->whenColumnsChange('status', 'start_at', 'end_at');
        })]
        private QueryGuard $roomBookings,
    ) {}

    public function hasOverlap(RoomState $room, Slot $slot, ?string $ignoringBookingId = null): bool
    {
        $ids = $this->roomBookings->observe($room->roomId, fn (): array => $this->connection->fetchFirstColumn(
            'SELECT booking_id FROM cw_ss_bookings WHERE room_id = ? AND status = ? AND start_at < ? AND end_at > ? AND booking_id != ? ORDER BY booking_id',
            [$room->roomId, BookingState::CONFIRMED, $slot->end->format('c'), $slot->start->format('c'), $ignoringBookingId ?? ''],
        ));
        return [] !== $ids;
    }

    public function usedMinutes(string $companyId, string $month, ?string $ignoringBookingId = null): int
    {
        $from = new \DateTimeImmutable($month.'-01 00:00 UTC');

        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (end_at - start_at)) / 60), 0) FROM cw_ss_bookings
             WHERE company_id = ? AND charged AND start_at >= ? AND start_at < ? AND booking_id != ?',
            [$companyId, $from->format('c'), $from->modify('+1 month')->format('c'), $ignoringBookingId ?? ''],
        );
    }

    public function observeUsedMinutes(CompanyState $company, string $month, ?string $ignoringBookingId = null): int
    {
        return $this->companyUsage->observe($company->companyId, fn (): int => $this->usedMinutes($company->companyId, $month, $ignoringBookingId));
    }

    /** @return list<string> */
    public function bookingIdsOnDay(string $roomId, string $day): array
    {
        $from = new \DateTimeImmutable($day.' 00:00 UTC');

        return $this->connection->fetchFirstColumn(
            'SELECT booking_id FROM cw_ss_bookings WHERE room_id = ? AND status = ? AND start_at >= ? AND start_at < ? ORDER BY booking_id',
            [$roomId, BookingState::CONFIRMED, $from->format('c'), $from->modify('+1 day')->format('c')],
        );
    }

    /** @return list<BookingState> */
    public function observeConfirmedOnDay(string $roomId, string $day): array
    {
        $ids = $this->roomBookings->observe($roomId, fn (): array => $this->bookingIdsOnDay($roomId, $day));
        return array_map(fn (string $id): BookingState => $this->states->observe(BookingState::class, $id), $ids);
    }
}
