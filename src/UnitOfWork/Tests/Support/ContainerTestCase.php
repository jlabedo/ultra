<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Ultra\EventStore\EventStore;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainer;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkExtension;
use Ultra\UnitOfWork\UnitOfWorkRunner;

abstract class ContainerTestCase extends TestCase
{
    private string $buildDir;

    protected function setUp(): void
    {
        $this->buildDir = dirname(__DIR__, 4).'/var/unit_of_work_tests/'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->buildDir);
    }

    /** @return UltraLight<UltraLightContainer> */
    protected function ultraLight(): UltraLight
    {
        return new UltraLight()
            ->withBuildDir($this->buildDir)
            ->withBundle(new UnitOfWorkExtension())
            ->withClass(RecordingEventStore::class)
            ->withAlias(EventStore::class, RecordingEventStore::class)
            ->withAlias('test.event_store', EventStore::class)
            ->withAlias('test.inner_event_store', RecordingEventStore::class)
            ->withAlias('test.unit_of_work', UnitOfWork::class)
            ->withAlias('test.transactions', TransactionManager::class)
            ->withAlias('test.runner', UnitOfWorkRunner::class)
        ;
    }
}
