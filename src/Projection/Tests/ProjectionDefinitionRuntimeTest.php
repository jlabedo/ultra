<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Ultra\EventStore\Event;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\ProjectionDefinition;
use Ultra\Projection\ProjectionRuntime;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('projection definition exposes id and partition dimension from attribute', function (): void {
    $definition = ProjectionDefinition::fromClass(TestProjectionClass::class);

    expect($definition->projectionId)->toBe('test_projection')
        ->and($definition->partitionDimension)->toBe('courseId');
});

test('stream query covers all handled event types globally', function (): void {
    $definition = ProjectionDefinition::fromClass(TestProjectionClass::class);
    $query = $definition->streamQuery();

    expect($query->isEmpty())->toBeFalse()
        ->and($query->hasGlobalBranch())->toBeTrue(); // all()->ofTypes() has no domain-id filter

    // Both event types must be matched
    $domainIds = ['courseId' => 'course-1'];
    expect($query->matches(new Event('student.subscribed', [], $domainIds)))->toBeTrue()
        ->and($query->matches(new Event('course.created', [], $domainIds)))->toBeTrue()
        ->and($query->matches(new Event('unrelated.event', [], $domainIds)))->toBeFalse();
});

test('segment stream query uses bucket-range predicate', function (): void {
    $definition = ProjectionDefinition::fromClass(TestProjectionClass::class);
    $query = $definition->streamQueryForSegment(0, 127);

    expect($query->isEmpty())->toBeFalse();
    $branch = $query->branches[0];
    expect($branch->bucketRange)->toBe(['dimension' => 'courseId', 'from' => 0, 'to' => 127]);
});

test('runtime apply dispatches event to matching handler', function (): void {
    $projection = new TestProjectionClass();
    $runtime = new ProjectionRuntime($projection, new UltraNormalizer());

    $runtime->apply(new Event('student.subscribed', ['studentId' => 'student-1', 'courseId' => 'course-1'], ['courseId' => 'course-1']));
    $runtime->apply(new Event('course.created', ['courseId' => 'course-2', 'name' => 'Math'], ['courseId' => 'course-2']));
    $runtime->apply(new Event('unrelated.event', [], ['courseId' => 'course-1']));

    expect($projection->handled)->toBe(['subscribed:student-1', 'created:course-2']);
});

test('runtime initialize calls the ProjectionInitializer method', function (): void {
    $projection = new TestProjectionClass();
    $runtime = new ProjectionRuntime($projection, new UltraNormalizer());

    expect($projection->initialized)->toBeFalse();
    $runtime->initialize();
    expect($projection->initialized)->toBeTrue();
});

test('runtime initialize is a no-op when no ProjectionInitializer method is present', function (): void {
    $runtime = new ProjectionRuntime(new TestProjectionClassWithoutInitializer(), new UltraNormalizer());
    $runtime->initialize(); // must not throw
    expect(true)->toBeTrue();
});

test('projection definition throws when class has no Projection attribute', function (): void {
    expect(fn () => ProjectionDefinition::fromClass(\stdClass::class))
        ->toThrow(\InvalidArgumentException::class);
});
