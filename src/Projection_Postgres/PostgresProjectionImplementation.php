<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\ProjectionSegmentStoreInterface;
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

    public function createSegmentStore(): ProjectionSegmentStoreInterface
    {
        return new PostgresProjectionSegmentStore($this->connection, $this->tablePrefix, $this->clock);
    }

    public function createTransactionManager(): ProjectionTransactionManagerInterface
    {
        return new PostgresProjectionTransactionManager($this->connection);
    }
}
