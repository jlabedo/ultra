<?php

declare(strict_types=1);

namespace MappedTableSymfonyExample;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\QueryGuard;
use Ultra\UnitOfWork\UnitOfWorkRunner;

final readonly class WorkshopRegistration
{
    public const string CAPACITY_GUARD = 'mapped_table_example_workshop_capacity';

    public function __construct(
        private UnitOfWorkRunner $runner,
        #[Autowire(service: WorkshopsDefinition::class)]
        private MappedRowSet $workshops,
        #[Autowire(service: ReservationsDefinition::class)]
        private MappedRowSet $reservations,
        #[Autowire(service: QueryGuard::class . '.' . self::CAPACITY_GUARD)]
        private QueryGuard $capacityGuard,
        private Connection $connection,
    ) {}

    /** @param array<string, int> $initialReservations Reservation reference to party size. */
    public function publish(string $id, string $title, int $capacity, array $initialReservations = []): void
    {
        if ($capacity < 1 || '' === trim($title)) {
            throw new \InvalidArgumentException('A workshop needs a title and at least one seat.');
        }

        $owner = ['workshop_id' => $id];
        $remaining = $capacity;
        $initial = [];
        foreach ($initialReservations as $reference => $seats) {
            self::assertPartySize($seats, $remaining);
            $remaining -= $seats;
            $initial[] = new MappedRow([...$owner, 'reference' => (string) $reference], [
                'seats' => $seats,
            ]);
        }

        $this->runner->run(function () use ($id, $title, $capacity, $owner, $initial): void {
            $this->workshops->withKey($id)->insert()->setColumns(['title' => $title, 'capacity' => $capacity]);
            $this->reservations->reconcileForOwner($owner, $initial);
        });
    }

    public function reserve(string $workshop, string $reference, int $seats): void
    {
        $this->runner->run(function () use ($workshop, $reference, $seats): void {
            $row = $this->workshops->withKey($workshop)->observe(['capacity']);
            if (null === $row) {
                throw new \DomainException(sprintf('Workshop "%s" is unavailable.', $workshop));
            }

            $capacity = $row['capacity'];
            $reserved = $this->capacityGuard->observe($workshop, fn(): int => $this->reservedSeats($workshop));
            self::assertPartySize($seats, $capacity - $reserved);

            $key = ['workshop_id' => $workshop, 'reference' => $reference];
            $this->reservations->withKey($key)->insert()->setColumn('seats', $seats);
        });
    }

    public function withdraw(string $workshop): void
    {
        $this->runner->run(function () use ($workshop): void {
            $owner = ['workshop_id' => $workshop];
            $this->reservations->observeKeysForOwner($owner);
            $this->reservations->reconcileForOwner($owner, []);
            $this->workshops->withKey($workshop)->delete();
        });
    }

    public function reservedSeats(string $workshop): int
    {
        return (int) $this->connection->fetchOne('SELECT COALESCE(SUM(seats), 0) FROM reservations WHERE workshop_id = ?', [
            $workshop,
        ]);
    }

    private static function assertPartySize(int $seats, int $remaining): void
    {
        if ($seats < 1) {
            throw new \InvalidArgumentException('A reservation needs at least one seat.');
        }
        if ($seats > $remaining) {
            throw new CapacityExceeded(sprintf('Requested %d seats, only %d remain.', $seats, $remaining));
        }
    }
}
