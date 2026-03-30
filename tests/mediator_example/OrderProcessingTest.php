<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ultra\Mediator\MediatorInterface;
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

final class OrderProcessingTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new Kernel(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        restore_exception_handler();
    }

    public function testOrderProcessingSucceedsAndAppliesAspects(): void
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

        $this->assertSame('order-1', $result);

        /** @var OrderRepository $orders */
        $orders = $container->get(OrderRepository::class);
        $this->assertCount(1, $orders->saved);
        $this->assertSame('order-1', $orders->saved[0]->orderId);

        /** @var PublishedEvents $publishedEvents */
        $publishedEvents = $container->get(PublishedEvents::class);
        $this->assertCount(1, $publishedEvents->events);
        $this->assertInstanceOf(OrderPlaced::class, $publishedEvents->events[0]);

        /** @var TransactionManager $tx */
        $tx = $container->get(TransactionManager::class);
        $this->assertContains('begin', $tx->log);
        $this->assertContains('commit', $tx->log);
        $this->assertNotContains('rollback', $tx->log);

        /** @var Metrics $metrics */
        $metrics = $container->get(Metrics::class);
        $this->assertSame(1, $metrics->success);
        $this->assertSame(0, $metrics->failure);

        /** @var CleanupTracker $cleanup */
        $cleanup = $container->get(CleanupTracker::class);
        $this->assertSame(1, $cleanup->calls);

        /** @var AuditLog $audit */
        $audit = $container->get(AuditLog::class);
        $this->assertCount(1, $audit->entries);
        $this->assertSame(PlaceOrder::class, $audit->entries[0]['message']);
        $this->assertSame('t-1', $audit->entries[0]['context']['traceId']);
    }

    public function testOrderProcessingFailureRollsBackAndRecordsMetrics(): void
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
            $this->fail('Expected payment failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('payment failed', $e->getMessage());
        }

        /** @var OrderRepository $orders */
        $orders = $container->get(OrderRepository::class);
        $this->assertCount(0, $orders->saved);

        /** @var TransactionManager $tx */
        $tx = $container->get(TransactionManager::class);
        $this->assertContains('begin', $tx->log);
        $this->assertContains('rollback', $tx->log);

        /** @var Metrics $metrics */
        $metrics = $container->get(Metrics::class);
        $this->assertSame(0, $metrics->success);
        $this->assertSame(1, $metrics->failure);

        /** @var CleanupTracker $cleanup */
        $cleanup = $container->get(CleanupTracker::class);
        $this->assertSame(1, $cleanup->calls);

        /** @var AuditLog $audit */
        $audit = $container->get(AuditLog::class);
        $this->assertCount(1, $audit->entries);
        $this->assertSame(PlaceOrder::class, $audit->entries[0]['message']);
    }

    public function testSelfHandlingMessageDispatchesThroughMediator(): void
    {
        self::bootKernel(['debug' => true]);
        $container = self::$kernel->getContainer();

        /** @var MediatorInterface $mediator */
        $mediator = $container->get('test.mediator');

        /** @var SelfHandlingTracker $tracker */
        $tracker = $container->get(SelfHandlingTracker::class);

        $message = new SelfHandledPing('ping');
        $result = $mediator->send($message);

        $this->assertSame('PING', $result);
        $this->assertSame(1, $tracker->calls);
        $this->assertSame(['ping'], $tracker->payloads);
    }
}
