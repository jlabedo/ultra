<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLease;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;
use Ultra\Time\ClockInterface;
use Ultra\Time\SystemClock;

final class PostgresProjectionImplementation implements ProjectionImplementationInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tablePrefix = 'projection_',
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function createSegmentRepository(): PostgresProjectionSegmentRepository
    {
        return new PostgresProjectionSegmentRepository($this->connection, $this->tablePrefix, $this->clock);
    }

    public function createDirtyKeyTracker(): DirtyKeyTracker
    {
        return new PostgresDirtyKeyTracker($this->connection, $this->tablePrefix . 'dirty_keys');
    }

    public function createCoordinatorLease(): CoordinatorLease
    {
        return new PostgresCoordinatorLease($this->connection, $this->tablePrefix . 'coordinator_lease', $this->clock);
    }

    public function createTransactionManager(): ProjectionTransactionManagerInterface
    {
        return new PostgresProjectionTransactionManager($this->connection);
    }
}
