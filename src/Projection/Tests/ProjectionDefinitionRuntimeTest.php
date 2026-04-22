<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Event;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\ProjectionDefinition;
use Ultra\Projection\ProjectionRuntime;

#[EventAttribute('student.subscribed')]
final readonly class StudentSubscribed
{
    public function __construct(
        public string $studentId,
        public string $courseId,
    ) {}
}

#[EventAttribute('course.created')]
final readonly class CourseCreatedEvent
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'test_projection', partitionBy: 'courseId')]
final class TestProjectionClass
{
    public bool $initialized = false;

    /** @var list<string> */
    public array $handled = [];

    #[ProjectionInitializer]
    public function initialize(): void
    {
        $this->initialized = true;
    }

    #[ProjectionHandler]
    public function onStudentSubscribed(StudentSubscribed $event): void
    {
        $this->handled[] = 'subscribed:' . $event->studentId;
    }

    #[ProjectionHandler]
    public function onCourseCreated(CourseCreatedEvent $event): void
    {
        $this->handled[] = 'created:' . $event->courseId;
    }
}

#[Projection(name: 'no_init_projection', partitionBy: 'courseId')]
final class TestProjectionClassWithoutInitializer
{
    #[ProjectionHandler]
    public function onStudentSubscribed(StudentSubscribed $event): void {}
}

#[CoversClass(ProjectionDefinition::class)]
#[CoversClass(ProjectionRuntime::class)]
final class ProjectionDefinitionRuntimeTest extends TestCase
{
    public function test_projection_definition_exposes_id_and_partition_dimension_from_attribute(): void
    {
        $definition = ProjectionDefinition::fromClass(TestProjectionClass::class);

        self::assertSame('test_projection', $definition->projectionId);
        self::assertSame('courseId', $definition->partitionDimension);
    }

    public function test_stream_query_covers_all_handled_event_types_globally(): void
    {
        $definition = ProjectionDefinition::fromClass(TestProjectionClass::class);
        $query = $definition->streamQuery();

        self::assertFalse($query->isEmpty());
        self::assertTrue($query->hasGlobalBranch());

        $domainIds = ['courseId' => 'course-1'];

        self::assertTrue($query->matches(new Event('student.subscribed', [], $domainIds)));
        self::assertTrue($query->matches(new Event('course.created', [], $domainIds)));
        self::assertFalse($query->matches(new Event('unrelated.event', [], $domainIds)));
    }

    public function test_segment_stream_query_uses_bucket_range_predicate(): void
    {
        $definition = ProjectionDefinition::fromClass(TestProjectionClass::class);
        $query = $definition->streamQueryForSegment(0, 127);

        self::assertFalse($query->isEmpty());
        self::assertSame(
            ['dimension' => 'courseId', 'from' => 0, 'to' => 127],
            $query->branches[0]->bucketRange,
        );
    }

    public function test_runtime_apply_dispatches_event_to_matching_handler(): void
    {
        $projection = new TestProjectionClass();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());

        $runtime->apply(new Event('student.subscribed', ['studentId' => 'student-1', 'courseId' => 'course-1'], ['courseId' => 'course-1']));
        $runtime->apply(new Event('course.created', ['courseId' => 'course-2', 'name' => 'Math'], ['courseId' => 'course-2']));
        $runtime->apply(new Event('unrelated.event', [], ['courseId' => 'course-1']));

        self::assertSame(['subscribed:student-1', 'created:course-2'], $projection->handled);
    }

    public function test_runtime_initialize_calls_the_projection_initializer_method(): void
    {
        $projection = new TestProjectionClass();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());

        self::assertFalse($projection->initialized);

        $runtime->initialize();

        self::assertTrue($projection->initialized);
    }

    public function test_runtime_initialize_is_a_no_op_when_no_projection_initializer_method_is_present(): void
    {
        $this->expectNotToPerformAssertions();

        $runtime = new ProjectionRuntime(new TestProjectionClassWithoutInitializer(), new UltraNormalizer());

        $runtime->initialize();
    }

    public function test_projection_definition_throws_when_class_has_no_projection_attribute(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProjectionDefinition::fromClass(\stdClass::class);
    }
}
