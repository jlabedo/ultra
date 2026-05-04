<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Attribute\ProjectionResetter;
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

    public int $flushes = 0;

    public bool $reset = false;

    /** @var list<string> */
    public array $handled = [];

    #[ProjectionInitializer]
    public function initialize(): void
    {
        $this->initialized = true;
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        ++$this->flushes;
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->reset = true;
    }

    #[ProjectionHandler]
    public function onStudentSubscribed(StudentSubscribed $event): void
    {
        $this->handled[] = 'subscribed:'.$event->studentId;
    }

    #[ProjectionHandler]
    public function onCourseCreated(CourseCreatedEvent $event): void
    {
        $this->handled[] = 'created:'.$event->courseId;
    }
}

#[Projection(name: 'no_init_projection', partitionBy: 'courseId')]
final class TestProjectionClassWithoutInitializer
{
    #[ProjectionHandler]
    public function onStudentSubscribed(StudentSubscribed $event): void {}
}

#[EventAttribute('student.subscribed')]
final readonly class DuplicateStudentSubscribed
{
    public function __construct(public string $studentId) {}
}

final readonly class EventWithoutAttribute
{
    public function __construct(public string $courseId) {}
}

#[Projection(name: 'invalid_builtin_projection', partitionBy: 'courseId')]
final class BuiltinParameterProjection
{
    #[ProjectionHandler]
    public function onInvalid(string $eventType): void {}
}

#[Projection(name: 'invalid_parameter_count_projection', partitionBy: 'courseId')]
final class InvalidParameterCountProjection
{
    #[ProjectionHandler]
    public function onInvalid(StudentSubscribed $event, CourseCreatedEvent $other): void {}
}

#[Projection(name: 'duplicate_handler_projection', partitionBy: 'courseId')]
final class DuplicateHandlerProjection
{
    #[ProjectionHandler]
    public function onStudentSubscribed(StudentSubscribed $event): void {}

    #[ProjectionHandler]
    public function onDuplicateStudentSubscribed(DuplicateStudentSubscribed $event): void {}
}

#[Projection(name: 'missing_event_attribute_projection', partitionBy: 'courseId')]
final class MissingEventAttributeProjection
{
    #[ProjectionHandler]
    public function onMissingAttribute(EventWithoutAttribute $event): void {}
}

#[Projection(name: 'invalid_initializer_projection', partitionBy: 'courseId')]
final class InvalidInitializerProjection
{
    #[ProjectionInitializer]
    public function initialize(string $projectionId): void {}
}

#[Projection(name: 'invalid_flush_projection', partitionBy: 'courseId')]
final class InvalidFlushProjection
{
    #[ProjectionFlush]
    public function flush(string $projectionId): void {}
}

#[Projection(name: 'invalid_resetter_projection', partitionBy: 'courseId')]
final class InvalidResetterProjection
{
    #[ProjectionResetter]
    public function reset(string $projectionId): void {}
}

/**
 * @internal
 */
#[CoversClass(ProjectionDefinition::class)]
#[CoversClass(ProjectionRuntime::class)]
final class ProjectionDefinitionRuntimeTest extends TestCase
{
    public function test_projection_definition_exposes_id_and_partition_dimension_from_attribute(): void
    {
        $definition = ProjectionDefinition::fromClass(TestProjectionClass::class);

        self::assertSame('test_projection', $definition->projectionId);
        self::assertSame('courseId', $definition->partitionDimension);
        self::assertSame('initialize', $definition->initializerMethod);
        self::assertSame('reset', $definition->resetterMethod);
        self::assertSame('flush', $definition->flushMethod);
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

    public function test_projection_definition_exposes_handler_lookup_bucket_hashing_and_handled_event_types(): void
    {
        $definition = ProjectionDefinition::fromProjection(new TestProjectionClass());
        $event = new Event(
            'student.subscribed',
            ['studentId' => 'student-1', 'courseId' => 'course-1'],
            ['courseId' => 'course-1'],
        );

        self::assertSame(['student.subscribed', 'course.created'], $definition->handledEventTypes());
        self::assertTrue($definition->handles($event));
        self::assertFalse($definition->handles(new Event('unrelated.event', [], ['studentId' => 'student-1'])));
        self::assertSame(BucketHasher::bucketFor('course-1'), $definition->bucketFor($event));
        self::assertNull($definition->bucketFor(new Event('student.subscribed', [], ['studentId' => 'student-1'])));

        $handler = $definition->handlerFor($event);

        self::assertNotNull($handler);
        self::assertSame('student.subscribed', $handler->eventType);
        self::assertSame(StudentSubscribed::class, $handler->eventClass);
        self::assertSame('onStudentSubscribed', $handler->method);
        self::assertSame($handler, $definition->handlerFor('student.subscribed'));
        self::assertNull($definition->handlerFor('missing.event'));
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

    public function test_runtime_flush_calls_the_projection_flush_method(): void
    {
        $projection = new TestProjectionClass();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());

        self::assertSame(0, $projection->flushes);

        $runtime->flush();
        $runtime->flush();

        self::assertSame(2, $projection->flushes);
    }

    public function test_runtime_flush_is_a_no_op_when_no_projection_flush_method_is_present(): void
    {
        $this->expectNotToPerformAssertions();

        $runtime = new ProjectionRuntime(new TestProjectionClassWithoutInitializer(), new UltraNormalizer());

        $runtime->flush();
    }

    public function test_runtime_reset_calls_the_projection_resetter_method(): void
    {
        $projection = new TestProjectionClass();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());

        self::assertFalse($projection->reset);

        $runtime->reset();

        self::assertTrue($projection->reset);
    }

    public function test_runtime_reset_is_a_no_op_when_no_projection_resetter_method_is_present(): void
    {
        $this->expectNotToPerformAssertions();

        $runtime = new ProjectionRuntime(new TestProjectionClassWithoutInitializer(), new UltraNormalizer());

        $runtime->reset();
    }

    public function test_projection_definition_throws_when_class_has_no_projection_attribute(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProjectionDefinition::fromClass(\stdClass::class);
    }

    public function test_projection_definition_throws_when_a_handler_uses_a_builtin_parameter_type(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must type-hint a specific event class');

        ProjectionDefinition::fromClass(BuiltinParameterProjection::class);
    }

    public function test_projection_definition_throws_when_a_handler_has_the_wrong_number_of_parameters(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must have exactly one parameter');

        ProjectionDefinition::fromClass(InvalidParameterCountProjection::class);
    }

    public function test_projection_definition_throws_when_multiple_handlers_target_the_same_event_type(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate #[ProjectionHandler]');

        ProjectionDefinition::fromClass(DuplicateHandlerProjection::class);
    }

    public function test_projection_definition_throws_when_the_event_class_has_no_event_attribute(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no #[Event] attribute');

        ProjectionDefinition::fromClass(MissingEventAttributeProjection::class);
    }

    public function test_projection_definition_throws_when_the_initializer_declares_parameters(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must have no parameters');

        ProjectionDefinition::fromClass(InvalidInitializerProjection::class);
    }

    public function test_projection_definition_throws_when_the_flush_method_declares_parameters(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must have no parameters');

        ProjectionDefinition::fromClass(InvalidFlushProjection::class);
    }

    public function test_projection_definition_throws_when_the_resetter_declares_parameters(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must have no parameters');

        ProjectionDefinition::fromClass(InvalidResetterProjection::class);
    }
}
