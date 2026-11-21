<?php

declare(strict_types=1);

namespace MappedTableSymfonyExample\Tests;

use MappedTableSymfonyExample\CapacityExceeded;
use MappedTableSymfonyExample\DemoDatabase;
use MappedTableSymfonyExample\WorkshopContainer;
use MappedTableSymfonyExample\WorkshopRegistration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\UnitOfWorkRunner;

final class WorkshopRegistrationTest extends TestCase
{
    private ?DemoDatabase $database = null;
    private WorkshopContainer $compiled;
    private Container $web;

    protected function setUp(): void
    {
        $dsn = getenv('MAPPED_TABLE_DATABASE_DSN') ?: getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set MAPPED_TABLE_DATABASE_DSN to run the workshop integration tests.');
        }

        $this->compiled = WorkshopContainer::compile();
        $this->database = DemoDatabase::open($dsn);
        $this->web = $this->compiled->using($this->database->connection);
    }

    protected function tearDown(): void
    {
        $this->database?->close();
    }

    public function test_publishing_and_withdrawing_manage_only_the_workshops_own_reservations(): void
    {
        $workshops = $this->web->get(WorkshopRegistration::class);
        $workshops->publish('woodwork', 'Woodwork', 6, ['same-reference' => 2]);
        $workshops->publish('pottery', 'Pottery', 8, ['same-reference' => 3]);
        $workshops->reserve('woodwork', 'group', 4);
        self::assertSame(6, $workshops->reservedSeats('woodwork'));

        $workshops->withdraw('woodwork');

        $connection = $this->database->connection;
        self::assertSame(['pottery'], $connection->fetchFirstColumn('SELECT id FROM workshops ORDER BY id'));
        self::assertSame(0, $workshops->reservedSeats('woodwork'));
        self::assertSame(3, $workshops->reservedSeats('pottery'));
    }

    public function test_the_last_seat_cannot_be_sold_twice_under_distinct_references(): void
    {
        $web = $this->web->get(WorkshopRegistration::class);
        $webRunner = $this->web->get(UnitOfWorkRunner::class);
        $web->publish('woodwork', 'Woodwork', 3, ['club' => 2]);
        $deskConnection = $this->database->newSession();

        try {
            $desk = $this->compiled->using($deskConnection)->get(WorkshopRegistration::class);
            try {
                $webRunner->run(static function () use ($web, $desk): void {
                    $web->reserve('woodwork', 'web', 1);
                    $desk->reserve('woodwork', 'desk', 1);
                });
                self::fail('The web decision used a seat the desk had already committed.');
            } catch (ConcurrencyException $error) {
                self::assertStringContainsString(WorkshopRegistration::CAPACITY_GUARD, $error->getMessage());
            }

            self::assertSame(3, $web->reservedSeats('woodwork'));
            self::assertSame(
                ['club', 'desk'],
                $this->database->connection->fetchFirstColumn('SELECT reference FROM reservations ORDER BY reference'),
            );

            $this->expectException(CapacityExceeded::class);
            $web->reserve('woodwork', 'web', 1);
        } finally {
            $deskConnection->close();
        }
    }
}
