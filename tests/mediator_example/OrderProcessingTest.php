<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\Mediator_Example\OrderProcessing\Domain\OrderPlaced;
use Ultra\Mediator_Example\OrderProcessing\Domain\PlaceOrder;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\AuditLog;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\CleanupTracker;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\Metrics;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\OrderRepository;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\PaymentGateway;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\PublishedEvents;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\SelfHandlingTracker;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\TransactionManager;
use Ultra\Mediator_Example\OrderProcessing\Kernel;
use Ultra\Mediator_Example\OrderProcessing\Message\SelfHandledPing;
use Ultra\MessageHandling\MediatorInterface;

/**
 * @internal
 *
 * @coversNothing
 */
final class OrderProcessingTest extends TestCase
{
    private static ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        restore_exception_handler();
    }

    public function test_order_processing_succeeds_and_applies_aspects(): void
    {
        self::bootKernel();
        $container = self::$kernel->getContainer();

        /** @var PaymentGateway $payments */
        $payments = $container->get(PaymentGateway::class);
        $payments->shouldFail = false;

        /** @var MediatorInterface $mediator */
        $mediator = $container->get('test.mediator');

        $command = new PlaceOrder('order-1', 1500, 'user-1');
        $result = $mediator->send($command, ['traceId' => 't-1']);

        static::assertSame('order-1', $result);

        /** @var OrderRepository $orders */
        $orders = $container->get(OrderRepository::class);
        static::assertCount(1, $orders->saved);
        static::assertSame('order-1', $orders->saved[0]->orderId);

        /** @var PublishedEvents $publishedEvents */
        $publishedEvents = $container->get(PublishedEvents::class);
        static::assertCount(1, $publishedEvents->events);
        static::assertInstanceOf(OrderPlaced::class, $publishedEvents->events[0]);

        /** @var TransactionManager $tx */
        $tx = $container->get(TransactionManager::class);
        static::assertContains('begin', $tx->log);
        static::assertContains('commit', $tx->log);
        static::assertNotContains('rollback', $tx->log);

        /** @var Metrics $metrics */
        $metrics = $container->get(Metrics::class);
        static::assertSame(1, $metrics->success);
        static::assertSame(0, $metrics->failure);

        /** @var CleanupTracker $cleanup */
        $cleanup = $container->get(CleanupTracker::class);
        static::assertSame(1, $cleanup->calls);

        /** @var AuditLog $audit */
        $audit = $container->get(AuditLog::class);
        static::assertCount(1, $audit->entries);
        static::assertSame(PlaceOrder::class, $audit->entries[0]['message']);
        static::assertSame('t-1', $audit->entries[0]['context']['traceId']);
    }

    public function test_order_processing_failure_rolls_back_and_records_metrics(): void
    {
        self::bootKernel();
        $container = self::$kernel->getContainer();

        /** @var PaymentGateway $payments */
        $payments = $container->get(PaymentGateway::class);
        $payments->shouldFail = true;

        /** @var MediatorInterface $mediator */
        $mediator = $container->get('test.mediator');

        $command = new PlaceOrder('order-2', 500, 'user-2');

        try {
            $mediator->send($command);
            static::fail('Expected payment failure');
        } catch (\RuntimeException $e) {
            static::assertSame('payment failed', $e->getMessage());
        }

        /** @var OrderRepository $orders */
        $orders = $container->get(OrderRepository::class);
        static::assertCount(0, $orders->saved);

        /** @var TransactionManager $tx */
        $tx = $container->get(TransactionManager::class);
        static::assertContains('begin', $tx->log);
        static::assertContains('rollback', $tx->log);

        /** @var Metrics $metrics */
        $metrics = $container->get(Metrics::class);
        static::assertSame(0, $metrics->success);
        static::assertSame(1, $metrics->failure);

        /** @var CleanupTracker $cleanup */
        $cleanup = $container->get(CleanupTracker::class);
        static::assertSame(1, $cleanup->calls);

        /** @var AuditLog $audit */
        $audit = $container->get(AuditLog::class);
        static::assertCount(1, $audit->entries);
        static::assertSame(PlaceOrder::class, $audit->entries[0]['message']);
    }

    public function test_self_handling_message_dispatches_through_mediator(): void
    {
        self::bootKernel(['debug' => true]);
        $container = self::$kernel->getContainer();

        /** @var MediatorInterface $mediator */
        $mediator = $container->get('test.mediator');

        /** @var SelfHandlingTracker $tracker */
        $tracker = $container->get(SelfHandlingTracker::class);

        $message = new SelfHandledPing('ping');
        $result = $mediator->send($message);

        static::assertSame('PING', $result);
        static::assertSame(1, $tracker->calls);
        static::assertSame(['ping'], $tracker->payloads);
    }

    protected static function bootKernel(array $options = []): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
        self::$kernel = self::createKernel($options);
        self::$kernel->boot();
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new Kernel($options['environment'] ?? 'test', $options['debug'] ?? true);
    }
}
