<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\Event as StoredEvent;
use Ultra\EventStore\EventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\InMemory\InMemoryProjectionImplementation;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionDefinition;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\OtherStubProjection;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\RuntimeProjection;
use Ultra\Projection\Tests\Support\SecondaryRuntimeProjection;
use Ultra\Projection\Tests\Support\StubProjection;
use Ultra\Projection\Tests\Support\ThirdStubProjection;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class ProjectionRegistrationTest extends ProjectionTestCase
{
    public function test_registered_runtimes_and_definitions_are_addressable_by_projection_id(): void
    {
        $runtime = new ProjectionRuntime(new RuntimeProjection(), new UltraNormalizer());
        $secondaryRuntime = new ProjectionRuntime(new SecondaryRuntimeProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);

        $catalog->add($secondaryRuntime);

        static::assertSame($runtime, $catalog->runtime('runtime_projection'));
        static::assertSame($secondaryRuntime, $catalog->runtime('secondary_runtime_projection'));
        static::assertSame($runtime->definition(), $catalog->definition('runtime_projection'));
        static::assertSame(
            ['runtime_projection', 'secondary_runtime_projection'],
            array_map(
                static fn(ProjectionDefinition $definition): string => $definition->projectionId,
                $catalog->definitions(),
            ),
        );
    }

    public function test_unknown_projection_ids_are_rejected(): void
    {
        $catalog = new ProjectionCatalog();

        try {
            $catalog->runtime('missing_projection');
            static::fail('Expected runtime lookup to fail for an unknown projection.');
        } catch (\InvalidArgumentException $e) {
            static::assertStringContainsString('Unknown projection "missing_projection"', $e->getMessage());
        }

        try {
            $catalog->definition('missing_projection');
            static::fail('Expected definition lookup to fail for an unknown projection.');
        } catch (\InvalidArgumentException $e) {
            static::assertStringContainsString('Unknown projection "missing_projection"', $e->getMessage());
        }
    }

    /**
     * @param list<class-string> $classes
     * @param list<string> $expectedIds
     */
    #[DataProvider('discoveredProjections')]
    public function test_each_discovered_projection_has_one_runtime_and_definition(
        array $classes,
        array $expectedIds,
    ): void {
        $catalog = $this->projectionUltraLight()->withClass(...$classes)->boot()->projectionCatalog();

        static::assertSame($expectedIds, array_map(
            static fn(ProjectionRuntime $runtime): string => $runtime->projectionId(),
            $catalog->runtimes(),
        ));
        static::assertSame($expectedIds, array_map(
            static fn(ProjectionDefinition $definition): string => $definition->projectionId,
            $catalog->definitions(),
        ));
        foreach ($expectedIds as $id) {
            static::assertSame($catalog->runtime($id)->definition(), $catalog->definition($id));
            static::assertSame(['projection.stubbed'], $catalog->definition($id)->handledEventTypes());
        }
    }

    public static function discoveredProjections(): iterable
    {
        yield 'empty' => [[], []];
        yield 'one' => [
            [StubProjection::class],
            ['stub_projection'],
        ];
        yield 'several' => [
            [StubProjection::class, OtherStubProjection::class, ThirdStubProjection::class],
            ['stub_projection',     'other_stub_projection',    'third_stub_projection'],
        ];
    }

    #[DataProvider('backends')]
    public function test_the_assembled_event_store_records_activity_when_events_are_appended(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $implementation = null === $fixture->connection
            ? new InMemoryProjectionImplementation()
            : new PostgresProjectionImplementation($fixture->connection, $fixture->prefix);
        $container = $this
            ->projectionUltraLight($fixture->store, $implementation)
            ->withClass(StubProjection::class)
            ->boot();

        $eventStore = $container->eventStore();
        $activity = $container->projectionActivity();
        $activator = $container->projectionActivator();

        static::assertInstanceOf(EventStore::class, $eventStore);
        static::assertInstanceOf(InMemoryProjectionActivator::class, $activator);

        $eventStore->append([
            new StoredEvent(eventType: 'projection.stubbed', payload: [], domainIds: ['id' => 'stub-1']),
            new StoredEvent(eventType: 'projection.stubbed', payload: [], domainIds: ['id' => 'stub-1']),
        ]);

        $pending = $activity->pendingInSegment('stub_projection', 0, 255);

        static::assertCount(1, $pending);
        static::assertSame(1, $pending[0]->counter);
        static::assertSame(1, $activator->workSignals());
    }
}
