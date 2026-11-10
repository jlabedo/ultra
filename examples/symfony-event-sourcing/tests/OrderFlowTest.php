<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use App\Command\PlaceOrder;
use App\Command\ShipOrder;
use App\Schema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\UnitOfWork\UnitOfWorkRunner;

/** @internal */
final class OrderFlowTest extends KernelTestCase
{
    private Connection $connection;
    private string $schema;
    private MediatorInterface $mediator;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->schema = 'example_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema);
        $container->get(Schema::class)->create();
        $this->mediator = $container->get(MediatorInterface::class);
    }

    public function test_commands_share_pending_history_and_projection_resumes_and_rebuilds(): void
    {
        self::getContainer()->get(UnitOfWorkRunner::class)->run(function (): void {
            $this->mediator->send(new PlaceOrder('order-1', 'Ada'));
            $this->mediator->send(new ShipOrder('order-1'));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_store_events'));
        });
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_store_events'));
        self::assertSame([], $this->rows());

        // A new kernel represents another console invocation / projection worker.
        $this->restartKernel();
        $coordinator = self::getContainer()->get(ProjectionCoordinator::class);
        self::assertSame(2, $coordinator->run('test-worker', batchSize: 1)->processedEvents);
        self::assertSame([['id' => 'order-1', 'customer' => 'Ada', 'status' => 'shipped']], $this->rows());
        self::assertSame(0, $coordinator->run('test-worker')->processedEvents);

        $this->mediator->send(new PlaceOrder('order-2', 'Grace'));
        self::assertSame(1, $coordinator->run('test-worker')->processedEvents);
        $expected = $this->rows();
        $runner = self::getContainer()->get(ProjectionRunner::class);
        $runtime = self::getContainer()->get(ProjectionCatalog::class)->runtime('order_summary');
        self::assertSame(3, $runner->replayFromBeginning($runtime, limit: 1));
        self::assertSame($expected, $this->rows());
    }

    public function test_decisions_use_persisted_state_and_reject_invalid_transitions(): void
    {
        $this->mediator->send(new PlaceOrder('order-1', 'Ada'));
        $this->restartKernel();
        $this->mediator->send(new ShipOrder('order-1'));
        $this->restartKernel();

        foreach ([
            new ShipOrder('missing'),
            new PlaceOrder('order-1', 'Duplicate'),
            new ShipOrder('order-1'),
        ] as $command) {
            try {
                $this->mediator->send($command);
                self::fail('The invalid transition must fail.');
            } catch (\DomainException) {
                self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_store_events'));
            }
        }
    }

    public function test_failed_work_discards_events_and_activity_and_runner_can_be_reused(): void
    {
        try {
            self::getContainer()->get(UnitOfWorkRunner::class)->run(function (): void {
                $this->mediator->send(new PlaceOrder('order-1', 'Ada'));
                $this->mediator->send(new PlaceOrder('order-1', 'Duplicate'));
            });
            self::fail('The duplicate order must fail.');
        } catch (\DomainException) {
            self::assertSame([], self::getContainer()->get(EventStore::class)->load(StreamQuery::all())->events);
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projection_dirty_keys'));
        }
        $this->mediator->send(new PlaceOrder('order-1', 'Ada'));
        self::assertSame(1, self::getContainer()->get(ProjectionCoordinator::class)->run('test-worker')->processedEvents);
        self::assertCount(1, $this->rows());
    }

    public function test_activity_failure_rolls_back_the_event_append(): void
    {
        $this->connection->executeStatement('ALTER TABLE projection_dirty_keys ADD CONSTRAINT reject_activity CHECK (counter < 0)');
        try {
            $this->mediator->send(new PlaceOrder('order-1', 'Ada'));
            self::fail('The activity write must fail.');
        } catch (DbalException) {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_store_events'));
            self::assertSame([], self::getContainer()->get(EventStore::class)->load(StreamQuery::all())->events);
        }
        $this->connection->executeStatement('ALTER TABLE projection_dirty_keys DROP CONSTRAINT reject_activity');
        $this->mediator->send(new PlaceOrder('order-1', 'Ada'));
        self::assertSame(1, self::getContainer()->get(ProjectionCoordinator::class)->run('test-worker')->processedEvents);
    }

    public function test_checkpoint_failure_rolls_back_flushed_rows_and_same_worker_can_retry(): void
    {
        $this->mediator->send(new PlaceOrder('order-1', 'Ada'));
        $runner = self::getContainer()->get(ProjectionRunner::class);
        $runtime = self::getContainer()->get(ProjectionCatalog::class)->runtime('order_summary');
        $runner->ensureInitialized($runtime);
        $before = $runner->listSegments('order_summary')[0]->checkpoint->serialize();
        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION reject_checkpoint() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.checkpoint <> OLD.checkpoint THEN
                    RAISE EXCEPTION 'Checkpoint unavailable';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        $this->connection->executeStatement('CREATE TRIGGER checkpoint_failure BEFORE UPDATE ON projection_segments FOR EACH ROW EXECUTE FUNCTION reject_checkpoint()');
        try {
            $runner->backfillClaimedOnce($runtime, 'order_summary', 0, 'test-worker');
            self::fail('The checkpoint write must fail.');
        } catch (DbalException) {
            self::assertSame([], $this->rows());
            self::assertSame($before, $runner->listSegments('order_summary')[0]->checkpoint->serialize());
        }
        $this->connection->executeStatement('DROP TRIGGER checkpoint_failure ON projection_segments');
        self::assertSame(1, $runner->backfillClaimedOnce($runtime, 'order_summary', 0, 'test-worker')->processedEvents);
        self::assertCount(1, $this->rows());
    }

    private function restartKernel(): void
    {
        $this->connection->close();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema);
        $this->mediator = self::getContainer()->get(MediatorInterface::class);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM order_summary ORDER BY id');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('SET search_path TO public');
            $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            $this->connection->close();
        }
        parent::tearDown();
    }
}
