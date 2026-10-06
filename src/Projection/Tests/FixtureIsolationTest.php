<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNothing;
use Ultra\Projection\Tests\Support\ProjectionTestCase;

/** @internal */
#[CoversNothing]
final class FixtureIsolationTest extends ProjectionTestCase
{
    public function test_failed_cleanup_reports_the_error_and_preserves_every_table(): void
    {
        $fixture = $this->fixture('postgres');
        $first = $fixture->prefix.'first_model';
        $blocked = $fixture->prefix.'blocked_model';
        $view = $fixture->prefix.'dependent_view';
        $fixture->registerReadModel($first);
        $fixture->registerReadModel($blocked);
        $fixture->connection->executeStatement('CREATE TABLE '.$first.' (value INTEGER)');
        $fixture->connection->executeStatement('INSERT INTO '.$first.' VALUES (7)');
        $fixture->connection->executeStatement('CREATE TABLE '.$blocked.' (value INTEGER)');
        $fixture->connection->executeStatement('CREATE VIEW '.$view.' AS SELECT value FROM '.$blocked);

        try {
            try {
                $fixture->close();
                self::fail('Expected the dependent view to prevent fixture cleanup.');
            } catch (DriverException $exception) {
                self::assertSame('2BP01', $exception->getSQLState());
            }

            self::assertSame(7, (int) $fixture->connection->fetchOne('SELECT value FROM '.$first));
            self::assertCount(0, $fixture->connection->fetchFirstColumn('SELECT value FROM '.$view));
            self::assertSame(0, $fixture->connection->getTransactionNestingLevel());
        } finally {
            $fixture->connection->executeStatement('DROP VIEW IF EXISTS '.$view);
        }

        $fixture->close();

        self::assertNull($fixture->connection->fetchOne('SELECT to_regclass(?)', [$first]));
        self::assertNull($fixture->connection->fetchOne('SELECT to_regclass(?)', [$blocked]));
    }
}
