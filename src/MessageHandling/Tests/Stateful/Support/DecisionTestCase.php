<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\Events\EventsExtension;
use Ultra\EventSourcedState\EventSourcedStateExtension;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\StatefulMessageHandling\StatefulMessageHandlingExtension;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\StateTableRegistry;
use Ultra\StateStore_Bundle\StateStoreExtension;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainer;

require_once __DIR__ . '/DecisionFixtures.php';

/** Assembles actual extensions and owns generated files; assertions stay in guarantee suites. */
abstract class DecisionTestCase extends TestCase
{
    /** @var list<string> */
    private array $buildDirs = [];

    protected function buildDirectory(): string
    {
        $dir = dirname(__DIR__, 5) . '/var/stateful-tests/' . bin2hex(random_bytes(8));
        $this->buildDirs[] = $dir;

        return $dir;
    }

    /**
     * @param list<class-string> $classes
     * @param list<class-string> $events
     * @return UltraLight<UltraLightContainer>
     */
    protected function application(array $classes, array $events = [], bool $stored = false): UltraLight
    {
        $dir = $this->buildDirectory();
        $application = new UltraLight()
            ->withBuildDir($dir)
            ->withBundle(
                new MessageHandlingExtension(),
                $this->mapperExtension(),
                new StatefulMessageHandlingExtension(),
            )
            ->withAlias('test.mediator', MediatorInterface::class)
            ->withClass(...$classes);

        if ([] !== $events) {
            $application = $application
                ->withBundle(new EventsExtension(), new EventSourcedStateExtension())
                ->withClass(...$events)
                ->withService(new InMemoryEventStore(), InMemoryEventStore::class)
                ->withService(new \Ultra\StatefulMessageHandling\Tests\DecisionEventBus(), EventBusInterface::class)
                ->withAlias(EventStore::class, InMemoryEventStore::class);
        }
        if ($stored) {
            $database = new InMemoryRowDatabase();
            $application = $application
                ->withBundle(new StateStoreExtension())
                ->withService($database, InMemoryRowDatabase::class)
                ->withService(
                    new InMemoryMappedRowSetAdapterFactory($database),
                    InMemoryMappedRowSetAdapterFactory::class,
                )
                ->withAlias(MappedRowSetAdapterFactory::class, InMemoryMappedRowSetAdapterFactory::class)
                ->withAlias('test.state_store', StateStore::class)
                ->withAlias('test.state_tables', StateTableRegistry::class);
        }

        return $application;
    }

    protected function mapperExtension(): MapperExtension
    {
        return new MapperExtension(
            generationNamespace: 'Ultra\\StatefulMessageHandling\\Tests\\Generated\\Compilation_'
                . bin2hex(random_bytes(8)),
        );
    }

    /** @return UltraLight<UltraLightContainer> */
    protected function courseApplication(string ...$commands): UltraLight
    {
        return $this->application([
            \Ultra\EventSourcedState\Tests\Example\State\CourseCapacityState::class,
            \Ultra\EventSourcedState\Tests\Example\State\StudentSubscriptionState::class,
            \Ultra\StatefulMessageHandling\Tests\Example\Command\CreateCourseCommand::class,
            \Ultra\StatefulMessageHandling\Tests\Example\Command\SubscribeStudentToCourseCommand::class,
            \Ultra\StatefulMessageHandling\Tests\Example\Command\UnsubscribeStudentFromCourseCommand::class,
            ...$commands,
        ], [
            \Ultra\EventSourcedState\Tests\Example\Event\CourseCreated::class,
            \Ultra\EventSourcedState\Tests\Example\Event\StudentSubscribed::class,
            \Ultra\EventSourcedState\Tests\Example\Event\StudentUnsubscribed::class,
        ]);
    }

    /** @return UltraLight<UltraLightContainer> */
    protected function storedApplication(string ...$commands): UltraLight
    {
        return $this->application([
            \Ultra\StatefulMessageHandling\Tests\DecisionOrderState::class,
            \Ultra\StatefulMessageHandling\Tests\DecisionTermsState::class,
            \Ultra\StatefulMessageHandling\Tests\DecisionAcceptanceState::class,
            \Ultra\StatefulMessageHandling\Tests\DependentMemberRow::class,
            \Ultra\StatefulMessageHandling\Tests\DependentCompanyRow::class,
            \Ultra\StatefulMessageHandling\Tests\DependentUsageRow::class,
            ...$commands,
        ], stored: true);
    }

    /** @return UltraLight<UltraLightContainer> */
    protected function dependentApplication(string ...$commands): UltraLight
    {
        return $this->application([
            \Ultra\StatefulMessageHandling\Tests\DependentMemberState::class,
            \Ultra\StatefulMessageHandling\Tests\DependentSeatsState::class,
            ...$commands,
        ], [
            \Ultra\StatefulMessageHandling\Tests\DependentMemberJoined::class,
            \Ultra\StatefulMessageHandling\Tests\DependentSeatTaken::class,
        ]);
    }

    /** @return UltraLight<UltraLightContainer> */
    protected function inspectedApplication(string $family): UltraLight
    {
        $classes = match ($family) {
            'events' => [
                \Ultra\EventSourcedState\Tests\Example\State\CourseCapacityState::class,
                \Ultra\StatefulMessageHandling\Tests\InspectDecisionCourse::class,
            ],
            'stored' => [
                \Ultra\StatefulMessageHandling\Tests\DecisionOrderState::class,
                \Ultra\StatefulMessageHandling\Tests\InspectDecisionOrder::class,
            ],
            'mixed' => [
                \Ultra\EventSourcedState\Tests\Example\State\CourseCapacityState::class,
                \Ultra\StatefulMessageHandling\Tests\DecisionOrderState::class,
                \Ultra\StatefulMessageHandling\Tests\InspectMixedDecision::class,
            ],
        };

        return $this->application(
            $classes,
            'stored' === $family ? [] : [\Ultra\EventSourcedState\Tests\Example\Event\CourseCreated::class],
            'events' !== $family,
        )->withService(
            new \Ultra\StatefulMessageHandling\Tests\DecisionService(),
            \Ultra\StatefulMessageHandling\Tests\DecisionService::class,
        );
    }

    protected function flush(UltraLightContainer $container): void
    {
        $database = $container->get(InMemoryRowDatabase::class);
        $tables = $container->get('test.state_tables');
        $database->beginTransaction();
        try {
            $tables->flush();
            $database->commit();
        } catch (\Throwable $exception) {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            throw $exception;
        } finally {
            $tables->clear();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->buildDirs as $dir) {
            self::removeGeneratedFiles($dir);
        }
        parent::tearDown();
    }

    protected static function removeGeneratedFiles(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        chmod($dir, 0o700);
        foreach (new \FilesystemIterator($dir) as $file) {
            if ($file->isDir()) {
                self::removeGeneratedFiles($file->getPathname());
            } else {
                chmod($file->getPathname(), 0o600);
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
