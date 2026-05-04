<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Bundle;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\Events\Attribute\Event;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Bundle\ProjectionBundle;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\ProjectionFunctionalTestCase;

#[Event('projection.stubbed')]
final readonly class StubProjectionEvent {}

#[Projection(name: 'stub_projection', partitionBy: 'id')]
final class StubProjection
{
    #[ProjectionHandler]
    public function onSomething(StubProjectionEvent $event): void {}
}

#[Projection(name: 'other_stub_projection', partitionBy: 'id')]
final class OtherStubProjection
{
    #[ProjectionHandler]
    public function onSomething(StubProjectionEvent $event): void {}
}

#[Projection(name: 'third_stub_projection', partitionBy: 'id')]
final class ThirdStubProjection
{
    #[ProjectionHandler]
    public function onSomething(StubProjectionEvent $event): void {}
}

/**
 * @internal
 */
#[CoversClass(ProjectionBundle::class)]
final class ProjectionBundleTest extends ProjectionFunctionalTestCase
{
    public function test_registers_empty_catalog_when_no_projections_are_discovered(): void
    {
        $catalog = $this->projectionUltraLight()
            ->boot()
            ->projectionCatalog()
        ;

        self::assertInstanceOf(ProjectionCatalog::class, $catalog);
        self::assertSame([], $catalog->definitions());
        self::assertSame([], $catalog->runtimes());
    }

    public function test_wraps_discovered_projections_in_runtimes(): void
    {
        $catalog = $this->projectionUltraLight()
            ->withClass(StubProjection::class)
            ->boot()
            ->projectionCatalog()
        ;

        self::assertInstanceOf(ProjectionCatalog::class, $catalog);
        self::assertCount(1, $catalog->runtimes());
        self::assertContainsOnlyInstancesOf(ProjectionRuntime::class, $catalog->runtimes());
        self::assertSame('stub_projection', $catalog->runtime('stub_projection')->projectionId());
        self::assertSame(['projection.stubbed'], $catalog->definition('stub_projection')->handledEventTypes());
    }

    public function test_catalog_receives_one_runtime_per_discovered_projection(): void
    {
        $catalog = $this->projectionUltraLight()
            ->withClass(StubProjection::class, OtherStubProjection::class, ThirdStubProjection::class)
            ->boot()
            ->projectionCatalog()
        ;

        self::assertInstanceOf(ProjectionCatalog::class, $catalog);
        self::assertSame([
            'stub_projection',
            'other_stub_projection',
            'third_stub_projection',
        ], array_map(
            static fn (ProjectionRuntime $runtime): string => $runtime->projectionId(),
            $catalog->runtimes(),
        ));
    }

}
