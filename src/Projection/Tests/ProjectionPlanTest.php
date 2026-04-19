<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\ProjectionPlan;

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

test('projection plan exposes id and partition dimension from attribute', function (): void {
    $plan = new ProjectionPlan(new TestProjectionClass(), new UltraNormalizer());

    expect($plan->projectionId())->toBe('test_projection')
        ->and($plan->partitionDimension())->toBe('courseId');
});

test('stream query covers all handled event types globally', function (): void {
    $plan = new ProjectionPlan(new TestProjectionClass(), new UltraNormalizer());
    $query = $plan->streamQuery();

    expect($query->isEmpty())->toBeFalse()
        ->and($query->hasGlobalBranch())->toBeTrue(); // all()->ofTypes() has no domain-id filter

    // Both event types must be matched
    $domainIds = ['courseId' => 'course-1'];
    expect($query->matches(new Event('student.subscribed', [], $domainIds)))->toBeTrue()
        ->and($query->matches(new Event('course.created', [], $domainIds)))->toBeTrue()
        ->and($query->matches(new Event('unrelated.event', [], $domainIds)))->toBeFalse();
});

test('segment stream query uses bucket-range predicate', function (): void {
    $plan = new ProjectionPlan(new TestProjectionClass(), new UltraNormalizer());
    $query = $plan->streamQueryForSegment(0, 127);

    expect($query->isEmpty())->toBeFalse();
    $branch = $query->branches[0];
    expect($branch->bucketRange)->toBe(['dimension' => 'courseId', 'from' => 0, 'to' => 127]);
});

test('apply dispatches event to matching handler', function (): void {
    $projection = new TestProjectionClass();
    $plan = new ProjectionPlan($projection, new UltraNormalizer());

    $plan->apply(new Event('student.subscribed', ['studentId' => 'student-1', 'courseId' => 'course-1'], ['courseId' => 'course-1']));
    $plan->apply(new Event('course.created', ['courseId' => 'course-2', 'name' => 'Math'], ['courseId' => 'course-2']));
    $plan->apply(new Event('unrelated.event', [], ['courseId' => 'course-1']));

    expect($projection->handled)->toBe(['subscribed:student-1', 'created:course-2']);
});

test('initialize calls the ProjectionInitializer method', function (): void {
    $projection = new TestProjectionClass();
    $plan = new ProjectionPlan($projection, new UltraNormalizer());

    expect($projection->initialized)->toBeFalse();
    $plan->initialize();
    expect($projection->initialized)->toBeTrue();
});

test('initialize is a no-op when no ProjectionInitializer method is present', function (): void {
    $plan = new ProjectionPlan(new TestProjectionClassWithoutInitializer(), new UltraNormalizer());
    $plan->initialize(); // must not throw
    expect(true)->toBeTrue();
});

test('projection plan throws when class has no Projection attribute', function (): void {
    expect(fn () => new ProjectionPlan(new \stdClass(), new UltraNormalizer()))
        ->toThrow(\InvalidArgumentException::class);
});
