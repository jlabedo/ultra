<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\DecisionEngine\Aspect\DecisionEngineAspect;
use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\DecisionEngine\Exception\CommandHasNoCheckException;
use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Tests\Example\Command\CreateCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Command\SubscribeStudentToCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Command\UnsubscribeStudentFromCourseCommand;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;
use Ultra\DecisionEngine\Tests\Example\State\StudentSubscriptionState;
use Ultra\DecisionEngine_Compiler\EntityLoaderCompilerPass;
use Ultra\Events\CompiledEventRegistry;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\EventTopologyProvider;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\TagNames;
use Ultra\MessageHandling_Compiler\MessageRouterCompilerPass;

/** Test command with service dependency (Clock) */
final class CommandWithClockDependency
{
    public function __construct(
        public string $courseId,
        public string $name,
        public int $maxCapacity,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        CourseCapacityState $course,
        \DateTimeInterface $clock,
    ): array {
        if ($course->exists()) {
            throw new \InvalidArgumentException('Course already exists');
        }

        return [new CourseCreated($this->courseId, $this->name, $this->maxCapacity)];
    }
}

final class RecordingEventBus implements EventBusInterface
{
    /** @var list<array{event: object, context: array}> */
    public array $calls = [];

    public function publish(object $event, array|MessageContext $context = []): void
    {
        $this->calls[] = [
            'event' => $event,
            'context' => MessageContext::coerce($context)->all(),
        ];
    }
}

/**
 * @return array<class-string, array<string, string>>
 */
function compiledEventRegistryDomainIdentifiers(): array
{
    return [
        CourseCreated::class => [
            'course' => 'courseId',
        ],
        StudentSubscribed::class => [
            'course' => 'courseId',
            'student' => 'studentId',
        ],
        StudentUnsubscribed::class => [
            'course' => 'courseId',
            'student' => 'studentId',
        ],
    ];
}

function createMediator(array $serviceDefinitions = []): array
{
    $container = new ContainerBuilder();
    $container->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
    $outputDir = sys_get_temp_dir() . '/decision-mediator-' . bin2hex(random_bytes(4));
    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0o777, true);
    }

    $container->register(UltraNormalizer::class, UltraNormalizer::class)
        ->setArguments([$outputDir . '/normalizer'])
    ;
    $container->register(EventTopologyProvider::class, StaticEventTopologyProvider::class)
        ->setArguments([[
            'course.created' => new EventTopology(['course']),
            'student.subscribed' => new EventTopology(['course', 'student']),
            'student.unsubscribed' => new EventTopology(['course', 'student']),
        ]])
    ;
    $container->register(InMemoryEventStore::class, InMemoryEventStore::class)
        ->setArguments([new Reference(EventTopologyProvider::class)])
        ->setPublic(true)
    ;
    $container->setAlias(EventStore::class, InMemoryEventStore::class)->setPublic(true);

    $container->register(CompiledEventRegistry::class, CompiledEventRegistry::class)
        ->setArguments([[
            'course.created' => CourseCreated::class,
            'student.subscribed' => StudentSubscribed::class,
            'student.unsubscribed' => StudentUnsubscribed::class,
        ], compiledEventRegistryDomainIdentifiers()])
    ;
    $container->setAlias(EventRegistryInterface::class, CompiledEventRegistry::class)->setPublic(true);

    $container->register(DecisionEngineAspect::class, DecisionEngineAspect::class)
        ->setAutowired(true)
        ->addTag('ultra.message_aspect')
    ;

    $container->register(\DateTimeInterface::class, \DateTimeImmutable::class)
        ->setArguments(['2020-01-01 00:00:00'])
    ;

    foreach (
        [
            CreateCourseCommand::class,
            SubscribeStudentToCourseCommand::class,
            UnsubscribeStudentFromCourseCommand::class,
            CommandWithClockDependency::class,
        ] as $commandClass
    ) {
        $container->register($commandClass, $commandClass)
            ->setAutoconfigured(false)
            ->addResourceTag(TagNames::SELF_HANDLER, ['method' => 'decide'])
        ;
    }

    foreach (
        [
            CourseCapacityState::class,
            StudentSubscriptionState::class,
        ] as $stateClass
    ) {
        $container->register($stateClass, $stateClass)
            ->setAutoconfigured(false)
            ->addResourceTag('decision_engine.entity')
        ;
    }

    foreach ($serviceDefinitions as $id => $definition) {
        if ($definition instanceof Definition) {
            $container->setDefinition($id, $definition);

            continue;
        }
        $container->set($id, $definition);
    }

    $container->setDefinition(
        MediatorInterface::class,
        (new Definition(MediatorInterface::class))
            ->addTag(TagNames::ROUTER, ['interface' => MediatorInterface::class])
            ->setAbstract(true),
    );
    $container->addCompilerPass(new EntityLoaderCompilerPass($outputDir . '/decision_engine'));
    $container->addCompilerPass(new MessageRouterCompilerPass($outputDir));
    $container->compile();

    return [
        'mediator' => $container->get('test.mediator'),
        'store' => $container->get(InMemoryEventStore::class),
        'container' => $container,
    ];
}

test('full handle flow: create course then subscribe student', function () {
    ['mediator' => $mediator, 'store' => $store] = createMediator();

    $createCmd = new CreateCourseCommand('math-101', 'Math', 10);
    $events1 = $mediator->send($createCmd);

    expect($events1)->toHaveCount(1);
    expect($events1[0])->toBeInstanceOf(CourseCreated::class);

    $subscribeCmd = new SubscribeStudentToCourseCommand('math-101', 'alice');
    $events2 = $mediator->send($subscribeCmd);

    expect($events2)->toHaveCount(1);
    expect($events2[0])->toBeInstanceOf(StudentSubscribed::class);

    $result = $store->load(new StreamQuery(['course' => 'math-101'], [
        'course.created',
        'student.subscribed',
        'student.unsubscribed',
    ]));
    expect($result->events)->toHaveCount(2);
});

test('handle returns the emitted events', function () {
    ['mediator' => $mediator] = createMediator();

    $events = $mediator->send(new CreateCourseCommand('c1', 'Course 1', 5));

    expect($events)->toHaveCount(1);
    expect($events[0])->toEqual(new CourseCreated('c1', 'Course 1', 5));
});

test('handle throws InvariantViolation when invariants fail', function () {
    ['mediator' => $mediator] = createMediator();

    $mediator->send(new CreateCourseCommand('math-101', 'Math', 1));
    $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));

    expect(fn() => $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'bob')))
        ->toThrow(InvariantViolation::class);
});

test('handle throws when creating course that already exists', function () {
    ['mediator' => $mediator] = createMediator();

    $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));

    expect(fn() => $mediator->send(new CreateCourseCommand('math-101', 'Other', 5)))
        ->toThrow(\InvalidArgumentException::class);
});

test('handle hydrates an empty state object when the stream is empty', function () {
    ['mediator' => $mediator] = createMediator();

    $events = $mediator->send(new CreateCourseCommand('new-course', 'New', 20));

    expect($events)->toHaveCount(1);
    expect($events[0]->courseId)->toBe('new-course');
});

test('handle returns a course.not_found violation when subscribing before course creation', function () {
    ['mediator' => $mediator] = createMediator();

    expect(fn() => $mediator->send(new SubscribeStudentToCourseCommand('missing-course', 'alice')))
        ->toThrow(InvariantViolation::class);

    $violations = $mediator->send(new SubscribeStudentToCourseCommand('missing-course', 'alice'), [
        'decision_mode' => 'check',
    ]);

    expect($violations)->toHaveCount(1);
    expect($violations[0]->code)->toBe('course.not_found');
});

test('handle allows multiple commands in sequence', function () {
    ['mediator' => $mediator, 'store' => $store] = createMediator();

    $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));
    $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));
    $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'bob'));

    $result = $store->load(new StreamQuery(['course' => 'math-101'], [
        'course.created',
        'student.subscribed',
        'student.unsubscribed',
    ]));
    expect($result->events)->toHaveCount(3);
});

test('check returns empty violations for valid state', function () {
    ['mediator' => $mediator] = createMediator();
    $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));

    $violations = $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'), [
        'decision_mode' => 'check',
    ]);

    expect($violations)->toBeArray();
    expect($violations)->toHaveCount(0);
});

test('check returns violations for invalid state', function () {
    ['mediator' => $mediator] = createMediator();
    $mediator->send(new CreateCourseCommand('math-101', 'Math', 1));
    $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));

    $violations = $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'bob'), [
        'decision_mode' => 'check',
    ]);

    expect($violations)->toHaveCount(1);
    expect($violations[0]->code)->toBe('course.full');
});

test('check throws if command has no check method', function () {
    ['mediator' => $mediator] = createMediator();

    expect(fn() => $mediator->send(new CreateCourseCommand('x', 'X', 1), [
        'decision_mode' => 'check',
    ]))->toThrow(CommandHasNoCheckException::class);
});

test('handle with service dependency resolves from container', function () {
    $clockDef = new Definition(\DateTimeImmutable::class);
    $clockDef->setArguments(['2020-06-15 12:00:00']);
    ['mediator' => $mediator, 'store' => $store] = createMediator([
        \DateTimeInterface::class => $clockDef,
    ]);

    $command = new CommandWithClockDependency('timed-1', 'Timed Course', 10);
    $events = $mediator->send($command);

    expect($events)->toHaveCount(1);
    expect($events[0])->toBeInstanceOf(CourseCreated::class);
    expect($events[0]->courseId)->toBe('timed-1');
    $result = $store->load(new StreamQuery(['course' => 'timed-1'], [
        'course.created',
    ]));
    expect($result->events)->toHaveCount(1);
});

test('concurrency: appending with stale version throws', function () {
    ['mediator' => $mediator, 'store' => $store] = createMediator();

    $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));
    $result = $store->load(new StreamQuery(['course' => 'math-101'], [
        'course.created',
        'student.subscribed',
        'student.unsubscribed',
    ]));
    $staleVersion = $result->queryVersion;

    $extraEvent = new AppendableEvent(
        eventType: 'course.created',
        payload: [
            'courseId' => 'math-101',
            'name' => 'Other',
            'maxCapacity' => 5,
        ],
        domainIds: ['course' => 'math-101'],
    );
    $store->append([$extraEvent], null);

    $anotherEvent = new AppendableEvent(
        eventType: 'course.created',
        payload: [
            'courseId' => 'math-101',
            'name' => 'Yet Another',
            'maxCapacity' => 3,
        ],
        domainIds: ['course' => 'math-101'],
    );
    expect(fn() => $store->append([$anotherEvent], $staleVersion))->toThrow(ConcurrencyException::class);
});

test('events are correctly routed to multiple streams', function () {
    ['mediator' => $mediator, 'store' => $store] = createMediator();

    $mediator->send(new CreateCourseCommand('math-101', 'Math', 10));
    $mediator->send(new SubscribeStudentToCourseCommand('math-101', 'alice'));

    $courseStream = $store->load(new StreamQuery(['course' => 'math-101'], [
        'course.created',
        'student.subscribed',
        'student.unsubscribed',
    ]));
    $studentStream = $store->load(new StreamQuery(['student' => 'alice'], [
        'student.subscribed',
        'student.unsubscribed',
    ]));

    expect($courseStream->events)->toHaveCount(2);
    expect($studentStream->events)->toHaveCount(1);
    expect($studentStream->events[0]->eventType)->toBe('student.subscribed');
});

test('decision engine publishes emitted events to the event bus when available', function () {
    ['mediator' => $mediator, 'container' => $container] = createMediator([
        EventBusInterface::class => new Definition(RecordingEventBus::class)
            ->setPublic(true),
    ]);
    $eventBus = $container->get(EventBusInterface::class);

    $events = $mediator->send(new CreateCourseCommand('math-101', 'Math', 10), ['traceId' => 'trace-123']);

    expect($events)->toHaveCount(1)
        ->and($eventBus->calls)->toHaveCount(1)
        ->and($eventBus->calls[0]['event'])->toEqual($events[0])
        ->and($eventBus->calls[0]['context']['traceId'])->toBe('trace-123')
    ;
});
