<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Ultra\DecisionEngine\Engine;
use Ultra\DecisionEngine\Engine\CommandMetadataResolver;
use Ultra\DecisionEngine\Engine\EventRouter;
use Ultra\DecisionEngine\Engine\MapEventClassRegistry;
use Ultra\DecisionEngine\Engine\StreamIdentityResolver;
use Ultra\DecisionEngine\Exception\CommandHasNoCheckException;
use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\DecisionEngine\Tests\Example\Command\CreateCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Command\SubscribeStudentToCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;
use Psr\Container\ContainerInterface;

/** Test command with service dependency (Clock) */
final class CommandWithClockDependency
{
    public function __construct(
        public string $courseId,
        public string $name,
        public int $maxCapacity,
    ) {}

    public function decide(
        ?\Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState $course,
        \DateTimeInterface $clock,
    ): array {
        if ($course !== null) {
            throw new \InvalidArgumentException('Course already exists');
        }
        return [new CourseCreated($this->courseId, $this->name, $this->maxCapacity)];
    }
}

/** Simple container for tests */
final class ArrayContainer implements ContainerInterface
{
    public function __construct(
        private array $entries = [],
    ) {
    }

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new \Exception("Service not found: {$id}");
    }

    public function has(string $id): bool
    {
        return isset($this->entries[$id]);
    }
}

function createEngine(?InMemoryEventStore $store = null, ?ContainerInterface $container = null): array
{
    $store = $store ?? new InMemoryEventStore();
    $eventClassRegistry = new MapEventClassRegistry([
        'course.created' => \Ultra\DecisionEngine\Tests\Example\Event\CourseCreated::class,
        'student.subscribed' => \Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed::class,
        'student.unsubscribed' => \Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed::class,
    ]);

    $engine = new Engine(
        $store,
        new CommandMetadataResolver(),
        new StreamIdentityResolver(),
        new EventRouter(),
        $eventClassRegistry,
        $container ?? new ArrayContainer(),
    );

    return ['engine' => $engine, 'store' => $store];
}

test('full handle flow: create course then subscribe student', function () {
    ['engine' => $engine, 'store' => $store] = createEngine();

    $createCmd = new CreateCourseCommand('math-101', 'Math', 10);
    $events1 = $engine->handle($createCmd);

    expect($events1)->toHaveCount(1);
    expect($events1[0])->toBeInstanceOf(CourseCreated::class);

    $subscribeCmd = new SubscribeStudentToCourseCommand('math-101', 'alice');
    $events2 = $engine->handle($subscribeCmd);

    expect($events2)->toHaveCount(1);
    expect($events2[0])->toBeInstanceOf(StudentSubscribed::class);

    $result = $store->load(new StreamQuery(['course' => 'math-101']));
    expect($result->events)->toHaveCount(2);
});

test('handle returns the emitted events', function () {
    ['engine' => $engine] = createEngine();

    $events = $engine->handle(new CreateCourseCommand('c1', 'Course 1', 5));

    expect($events)->toHaveCount(1);
    expect($events[0])->toEqual(new CourseCreated('c1', 'Course 1', 5));
});

test('handle throws InvariantViolation when invariants fail', function () {
    ['engine' => $engine] = createEngine();

    $engine->handle(new CreateCourseCommand('math-101', 'Math', 1));
    $engine->handle(new SubscribeStudentToCourseCommand('math-101', 'alice'));

    expect(fn () => $engine->handle(new SubscribeStudentToCourseCommand('math-101', 'bob')))
        ->toThrow(InvariantViolation::class);
});

test('handle throws when creating course that already exists', function () {
    ['engine' => $engine] = createEngine();

    $engine->handle(new CreateCourseCommand('math-101', 'Math', 10));

    expect(fn () => $engine->handle(new CreateCourseCommand('math-101', 'Other', 5)))
        ->toThrow(\InvalidArgumentException::class);
});

test('handle passes null for nullable state when stream is empty', function () {
    ['engine' => $engine] = createEngine();

    $events = $engine->handle(new CreateCourseCommand('new-course', 'New', 20));

    expect($events)->toHaveCount(1);
    expect($events[0]->courseId)->toBe('new-course');
});

test('handle allows multiple commands in sequence', function () {
    ['engine' => $engine, 'store' => $store] = createEngine();

    $engine->handle(new CreateCourseCommand('math-101', 'Math', 10));
    $engine->handle(new SubscribeStudentToCourseCommand('math-101', 'alice'));
    $engine->handle(new SubscribeStudentToCourseCommand('math-101', 'bob'));

    $result = $store->load(new StreamQuery(['course' => 'math-101']));
    expect($result->events)->toHaveCount(3);
});

test('check returns empty violations for valid state', function () {
    ['engine' => $engine] = createEngine();
    $engine->handle(new CreateCourseCommand('math-101', 'Math', 10));

    $violations = $engine->check(new SubscribeStudentToCourseCommand('math-101', 'alice'));

    expect($violations)->toBeArray();
    expect($violations)->toHaveCount(0);
});

test('check returns violations for invalid state', function () {
    ['engine' => $engine] = createEngine();
    $engine->handle(new CreateCourseCommand('math-101', 'Math', 1));
    $engine->handle(new SubscribeStudentToCourseCommand('math-101', 'alice'));

    $violations = $engine->check(new SubscribeStudentToCourseCommand('math-101', 'bob'));

    expect($violations)->toHaveCount(1);
    expect($violations[0]->code)->toBe('course.full');
});

test('check throws if command has no check method', function () {
    ['engine' => $engine] = createEngine();

    expect(fn () => $engine->check(new CreateCourseCommand('x', 'X', 1)))
        ->toThrow(CommandHasNoCheckException::class);
});

test('handle with service dependency resolves from container', function () {
    $clock = new \DateTimeImmutable('2020-06-15 12:00:00');
    $container = new ArrayContainer([\DateTimeInterface::class => $clock]);
    ['engine' => $engine, 'store' => $store] = createEngine(null, $container);

    $command = new CommandWithClockDependency('timed-1', 'Timed Course', 10);
    $events = $engine->handle($command);

    expect($events)->toHaveCount(1);
    expect($events[0])->toBeInstanceOf(CourseCreated::class);
    expect($events[0]->courseId)->toBe('timed-1');
    $result = $store->load(new StreamQuery(['course' => 'timed-1']));
    expect($result->events)->toHaveCount(1);
});

test('concurrency: appending with stale version throws', function () {
    ['engine' => $engine, 'store' => $store] = createEngine();

    $engine->handle(new CreateCourseCommand('math-101', 'Math', 10));
    $result = $store->load(new StreamQuery(['course' => 'math-101']));
    $staleVersion = $result->streamVersion;

    $router = new EventRouter();
    $extraEvent = $router->toAppendableEvent(new CourseCreated('math-101', 'Other', 5));
    $store->append([$extraEvent], null);

    $anotherEvent = $router->toAppendableEvent(new CourseCreated('math-101', 'Yet Another', 3));
    expect(fn () => $store->append([$anotherEvent], $staleVersion))->toThrow(ConcurrencyException::class);
});

test('events are correctly routed to multiple streams', function () {
    ['engine' => $engine, 'store' => $store] = createEngine();

    $engine->handle(new CreateCourseCommand('math-101', 'Math', 10));
    $engine->handle(new SubscribeStudentToCourseCommand('math-101', 'alice'));

    $courseStream = $store->load(new StreamQuery(['course' => 'math-101']));
    $studentStream = $store->load(new StreamQuery(['student' => 'alice']));

    expect($courseStream->events)->toHaveCount(2);
    expect($studentStream->events)->toHaveCount(1);
    expect($studentStream->events[0]->eventType)->toBe('student.subscribed');
});
