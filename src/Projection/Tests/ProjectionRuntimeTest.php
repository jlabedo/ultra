<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionDefinition;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\ProjectionFunctionalTestCase;

#[EventAttribute('course.created')]
final readonly class RuntimeCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'runtime_projection', partitionBy: 'courseId')]
final class RuntimeProjection
{
    /** @var array<string, string> */
    public array $names = [];

    #[ProjectionHandler]
    public function onCourseCreated(RuntimeCourseCreated $event): void
    {
        $this->names[$event->courseId] = $event->name;
    }
}

#[Projection(name: 'secondary_runtime_projection', partitionBy: 'courseId')]
final class SecondaryRuntimeProjection
{
    #[ProjectionHandler]
    public function onCourseCreated(RuntimeCourseCreated $event): void {}
}

/**
 * @internal
 */
#[CoversClass(ProjectionCatalog::class)]
#[CoversClass(ProjectionRuntime::class)]
final class ProjectionRuntimeTest extends ProjectionFunctionalTestCase
{
    public function test_projection_catalog_tracks_constructor_and_added_runtimes(): void
    {
        $runtime = new ProjectionRuntime(new RuntimeProjection(), new UltraNormalizer());
        $secondaryRuntime = new ProjectionRuntime(new SecondaryRuntimeProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);

        $catalog->add($secondaryRuntime);

        self::assertSame($runtime, $catalog->runtime('runtime_projection'));
        self::assertSame($secondaryRuntime, $catalog->runtime('secondary_runtime_projection'));
        self::assertSame($runtime->definition(), $catalog->definition('runtime_projection'));
        self::assertSame(
            ['runtime_projection', 'secondary_runtime_projection'],
            array_map(
                static fn (ProjectionDefinition $definition): string => $definition->projectionId,
                $catalog->definitions(),
            ),
        );
    }

    public function test_projection_catalog_throws_for_unknown_projections(): void
    {
        $catalog = new ProjectionCatalog();

        try {
            $catalog->runtime('missing_projection');
            self::fail('Expected runtime lookup to fail for an unknown projection.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown projection "missing_projection"', $e->getMessage());
        }

        try {
            $catalog->definition('missing_projection');
            self::fail('Expected definition lookup to fail for an unknown projection.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown projection "missing_projection"', $e->getMessage());
        }
    }

    public function test_projection_runtime_uses_an_explicit_definition_when_one_is_provided(): void
    {
        $definition = ProjectionDefinition::fromClass(RuntimeProjection::class);
        $runtime = new ProjectionRuntime(new \stdClass(), new UltraNormalizer(), $definition);

        self::assertSame('runtime_projection', $runtime->projectionId());
        self::assertSame($definition, $runtime->definition());
    }

}
