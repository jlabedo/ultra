<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionDefinition;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\BuiltinParameterProjection;
use Ultra\Projection\Tests\Support\DuplicateHandlerProjection;
use Ultra\Projection\Tests\Support\InvalidFlushProjection;
use Ultra\Projection\Tests\Support\InvalidInitializerProjection;
use Ultra\Projection\Tests\Support\InvalidParameterCountProjection;
use Ultra\Projection\Tests\Support\InvalidResetterProjection;
use Ultra\Projection\Tests\Support\MissingEventAttributeProjection;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\RuntimeProjection;
use Ultra\Projection\Tests\Support\StudentSubscribed;
use Ultra\Projection\Tests\Support\TestProjectionClass;
use Ultra\Projection\Tests\Support\TestProjectionClassWithoutInitializer;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class DefinitionContractTest extends ProjectionTestCase
{
    public function test_declared_identity_partition_and_lifecycle_hooks_define_the_projection(): void
    {
        $definition = ProjectionDefinition::fromClass(TestProjectionClass::class);

        self::assertSame('test_projection', $definition->projectionId);
        self::assertSame('courseId', $definition->partitionDimension);
        self::assertSame('initialize', $definition->initializerMethod);
        self::assertSame('reset', $definition->resetterMethod);
        self::assertSame('flush', $definition->flushMethod);
    }

    public function test_projection_query_accepts_only_its_handled_event_types(): void
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

    public function test_event_routing_uses_the_declared_type_and_partition_dimension(): void
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

    public function test_segment_query_restricts_the_declared_partition_to_its_bucket_range(): void
    {
        $definition = ProjectionDefinition::fromClass(TestProjectionClass::class);
        $query = $definition->streamQueryForSegment(0, 127);

        self::assertFalse($query->isEmpty());
        self::assertSame(
            ['dimension' => 'courseId', 'from' => 0, 'to' => 127],
            $query->branches[0]->bucketRange,
        );
    }

    public function test_payloads_reach_the_matching_handler_and_unhandled_events_are_ignored(): void
    {
        $projection = new TestProjectionClass();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());

        $runtime->apply(new Event('student.subscribed', ['studentId' => 'student-1', 'courseId' => 'course-1'], ['courseId' => 'course-1']));
        $runtime->apply(new Event('course.created', ['courseId' => 'course-2', 'name' => 'Math'], ['courseId' => 'course-2']));
        $runtime->apply(new Event('unrelated.event', [], ['courseId' => 'course-1']));

        self::assertSame(['subscribed:student-1', 'created:course-2'], $projection->handled);
    }

    public function test_an_explicit_definition_controls_runtime_identity(): void
    {
        $definition = ProjectionDefinition::fromClass(RuntimeProjection::class);
        $runtime = new ProjectionRuntime(new \stdClass(), new UltraNormalizer(), $definition);

        self::assertSame('runtime_projection', $runtime->projectionId());
        self::assertSame($definition, $runtime->definition());
    }

    public function test_optional_lifecycle_hooks_are_invoked_when_declared(): void
    {
        $projection = new TestProjectionClass();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());

        $runtime->initialize();
        $runtime->flush();
        $runtime->flush();
        $runtime->reset();

        self::assertTrue($projection->initialized);
        self::assertSame(2, $projection->flushes);
        self::assertTrue($projection->reset);
    }

    public function test_missing_lifecycle_hooks_are_optional(): void
    {
        $runtime = new ProjectionRuntime(new TestProjectionClassWithoutInitializer(), new UltraNormalizer());
        $runtime->initialize();
        $runtime->flush();
        $runtime->reset();

        self::assertNull($runtime->definition()->initializerMethod);
        self::assertNull($runtime->definition()->flushMethod);
        self::assertNull($runtime->definition()->resetterMethod);
    }

    /**
     * @param class-string $projectionClass
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('invalidDeclarations')]
    public function test_invalid_declarations_are_rejected(string $projectionClass, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        ProjectionDefinition::fromClass($projectionClass);
    }

    public static function invalidDeclarations(): iterable
    {
        yield 'missing projection attribute' => [\stdClass::class, \InvalidArgumentException::class, 'must be annotated'];
        yield 'builtin event parameter' => [BuiltinParameterProjection::class, \LogicException::class, 'must type-hint a specific event class'];
        yield 'wrong handler arity' => [InvalidParameterCountProjection::class, \LogicException::class, 'must have exactly one parameter'];
        yield 'duplicate event handler' => [DuplicateHandlerProjection::class, \LogicException::class, 'Duplicate #[ProjectionHandler]'];
        yield 'missing event attribute' => [MissingEventAttributeProjection::class, \LogicException::class, 'has no #[Event] attribute'];
        yield 'initializer parameters' => [InvalidInitializerProjection::class, \LogicException::class, 'must have no parameters'];
        yield 'flush parameters' => [InvalidFlushProjection::class, \LogicException::class, 'must have no parameters'];
        yield 'resetter parameters' => [InvalidResetterProjection::class, \LogicException::class, 'must have no parameters'];
    }
}
