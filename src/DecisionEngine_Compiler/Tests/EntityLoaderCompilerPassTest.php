<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine_Compiler\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\DecisionEngine\Attribute\EventSourcedEntity;
use Ultra\DecisionEngine\Attribute\EventSourcingHandler;
use Ultra\DecisionEngine\Attribute\MatchTag;
use Ultra\DecisionEngine\Engine;
use Ultra\DecisionEngine\Exception\DuplicateTagValueException;
use Ultra\DecisionEngine\Exception\MissingTagValueException;
use Ultra\DecisionEngine\Exception\UnexpectedTagValueException;
use Ultra\DecisionEngine\Exception\UnknownEventSourcedEntityException;
use Ultra\DecisionEngine_Compiler\EntityLoaderCompilerPass;
use Ultra\Events\Attribute\DomainIdentifier as DomainIdentifierAttribute;
use Ultra\Events\Attribute\Event;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event as StoredEvent;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\EventTopologyProvider;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\Mapper\UltraNormalizer;

const ENTITY_LOADER_TEST_OUTPUT_DIR = __DIR__.'/../../../var/decision_engine_compiler_tests';

#[Event('entity.course_created')]
final readonly class EntityCourseCreated
{
    public function __construct(
        #[DomainIdentifierAttribute('course')]
        public string $courseId,
        public int $capacity,
    ) {}
}

#[Event('entity.course_capacity_changed')]
final readonly class EntityCourseCapacityChanged
{
    public function __construct(
        #[DomainIdentifierAttribute('course')]
        public string $courseId,
        public int $capacity,
    ) {}
}

#[Event('entity.student_enrolled')]
final readonly class EntityStudentEnrolled
{
    public function __construct(
        #[DomainIdentifierAttribute('student')]
        public string $studentId,
    ) {}
}

#[Event('entity.student_subscribed')]
final readonly class EntityStudentSubscribed
{
    public function __construct(
        #[DomainIdentifierAttribute('course')]
        public string $courseId,
        #[DomainIdentifierAttribute('student')]
        public string $studentId,
    ) {}
}

#[Event('entity.student_unsubscribed')]
final readonly class EntityStudentUnsubscribed
{
    public function __construct(
        #[DomainIdentifierAttribute('course')]
        public string $courseId,
        #[DomainIdentifierAttribute('student')]
        public string $studentId,
    ) {}
}

#[Event('entity.pair_confirmed')]
final readonly class EntityPairConfirmed
{
    public function __construct(
        #[DomainIdentifierAttribute('course')]
        public string $courseId,
        #[DomainIdentifierAttribute('student')]
        public string $studentId,
        public string $status,
    ) {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class SubscriptionState
{
    public int $capacity = 0;
    public bool $studentKnown = false;
    public int $subscriptionDelta = 0;

    /** @var list<string> */
    public array $applied = [];

    #[EventSourcingHandler]
    public function onCourseCreated(EntityCourseCreated $event): void
    {
        $this->capacity = $event->capacity;
        $this->applied[] = 'created';
    }

    #[EventSourcingHandler]
    public function onCourseCapacityChanged(EntityCourseCapacityChanged $event): void
    {
        $this->capacity = $event->capacity;
        $this->applied[] = 'capacity_changed';
    }

    #[EventSourcingHandler]
    public function onStudentEnrolled(EntityStudentEnrolled $event): void
    {
        $this->studentKnown = true;
        $this->applied[] = 'student_enrolled';
    }

    #[EventSourcingHandler]
    #[MatchTag('course')]
    #[MatchTag('student')]
    public function onStudentSubscribed(EntityStudentSubscribed $event): void
    {
        ++$this->subscriptionDelta;
        $this->applied[] = 'student_subscribed';
    }

    #[EventSourcingHandler]
    #[MatchTag('course')]
    #[MatchTag('student')]
    public function onStudentUnsubscribed(EntityStudentUnsubscribed $event): void
    {
        --$this->subscriptionDelta;
        $this->applied[] = 'student_unsubscribed';
    }
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class ExactSubscriptionState
{
    /** @var list<string> */
    public array $statuses = [];

    #[EventSourcingHandler]
    #[MatchTag('course', 'student')]
    public function onPairConfirmed(EntityPairConfirmed $event): void
    {
        $this->statuses[] = $event->status;
    }
}

final class NotAnEntityState {}

function createEntityDefinition(string $class): Definition
{
    return (new Definition($class))
        ->addResourceTag('decision_engine.entity');
}

function createEngineContainer(string $suffix, array $definitions = []): ContainerBuilder
{
    $container = new ContainerBuilder();
    $cacheDir = ENTITY_LOADER_TEST_OUTPUT_DIR.'/'.$suffix.'/normalizer';
    $outputDir = ENTITY_LOADER_TEST_OUTPUT_DIR.'/'.$suffix.'/loaders';

    $container->register(UltraNormalizer::class, UltraNormalizer::class)
        ->setArguments([$cacheDir])
        ->setPublic(true)
    ;

    $container->register(EventTopologyProvider::class, StaticEventTopologyProvider::class)
        ->setArguments([[
            'entity.course_created' => new EventTopology(['course']),
            'entity.course_capacity_changed' => new EventTopology(['course']),
            'entity.student_enrolled' => new EventTopology(['student']),
            'entity.student_subscribed' => new EventTopology(['course', 'student']),
            'entity.student_unsubscribed' => new EventTopology(['course', 'student']),
            'entity.pair_confirmed' => new EventTopology(['course', 'student']),
        ]])
        ->setPublic(true)
    ;

    $container->register(InMemoryEventStore::class, InMemoryEventStore::class)
        ->setArguments([new \Symfony\Component\DependencyInjection\Reference(EventTopologyProvider::class)])
        ->setPublic(true)
    ;
    $container->setAlias(EventStore::class, InMemoryEventStore::class)->setPublic(true);

    $definitions = array_merge([
        SubscriptionState::class => createEntityDefinition(SubscriptionState::class),
        ExactSubscriptionState::class => createEntityDefinition(ExactSubscriptionState::class),
    ], $definitions);

    foreach ($definitions as $id => $definition) {
        $container->setDefinition($id, $definition);
    }

    $container->addCompilerPass(new EntityLoaderCompilerPass($outputDir));
    $container->compile();

    return $container;
}

function appendEvent(ContainerBuilder $container, StoredEvent ...$events): void
{
    $container->get(InMemoryEventStore::class)->append($events);
}

test('compiler pass generates loaders and engine hydrates OR-matched entities', function () {
    $container = createEngineContainer('or-success');
    $generatedLoaderFiles = glob(ENTITY_LOADER_TEST_OUTPUT_DIR.'/or-success/loaders/CompiledEventSourcedEntityLoader_SubscriptionState_*.php');

    appendEvent(
        $container,
        new StoredEvent('entity.course_created', ['courseId' => 'course-1', 'capacity' => 10], ['course' => 'course-1']),
        new StoredEvent('entity.student_enrolled', ['studentId' => 'student-1'], ['student' => 'student-1']),
        new StoredEvent('entity.student_subscribed', ['courseId' => 'course-1', 'studentId' => 'student-1'], ['course' => 'course-1', 'student' => 'student-1']),
        new StoredEvent('entity.course_capacity_changed', ['courseId' => 'course-1', 'capacity' => 25], ['course' => 'course-1']),
        new StoredEvent('entity.student_unsubscribed', ['courseId' => 'course-1', 'studentId' => 'student-1'], ['course' => 'course-1', 'student' => 'student-1']),
    );

    $engine = $container->get(Engine::class);
    $state = $engine->load(
        SubscriptionState::class,
        new DomainIdentifier('course', 'course-1'),
        new DomainIdentifier('student', 'student-1'),
    );

    expect($generatedLoaderFiles)->not->toBeFalse();
    expect($generatedLoaderFiles)->toHaveCount(1);
    expect(file_get_contents($generatedLoaderFiles[0]))->toContain('\Ultra\DecisionEngine\EventSourcedTagValueResolver::resolve');

    expect($state)->toBeInstanceOf(SubscriptionState::class)
        ->and($state->capacity)->toBe(25)
        ->and($state->studentKnown)->toBeTrue()
        ->and($state->subscriptionDelta)->toBe(0)
        ->and($state->applied)->toBe([
            'created',
            'student_enrolled',
            'student_subscribed',
            'capacity_changed',
            'student_unsubscribed',
        ]);
});

test('compiler pass generates AND criteria groups for multi-tag match clauses', function () {
    $container = createEngineContainer('and-success');
    $generatedLoaderFiles = glob(ENTITY_LOADER_TEST_OUTPUT_DIR.'/and-success/loaders/CompiledEventSourcedEntityLoader_ExactSubscriptionState_*.php');

    appendEvent(
        $container,
        new StoredEvent('entity.pair_confirmed', ['courseId' => 'course-1', 'studentId' => 'student-1', 'status' => 'matched'], ['course' => 'course-1', 'student' => 'student-1']),
        new StoredEvent('entity.pair_confirmed', ['courseId' => 'course-1', 'studentId' => 'student-2', 'status' => 'other-student'], ['course' => 'course-1', 'student' => 'student-2']),
    );

    $engine = $container->get(Engine::class);
    $state = $engine->load(
        ExactSubscriptionState::class,
        new DomainIdentifier('course', 'course-1'),
        new DomainIdentifier('student', 'student-1'),
    );

    expect($generatedLoaderFiles)->not->toBeFalse();
    expect($generatedLoaderFiles)->toHaveCount(1);
    expect(file_get_contents($generatedLoaderFiles[0]))
        ->toContain("'entity.pair_confirmed' => \$state->onPairConfirmed(\$this->normalizer->fromScalar(\$event->payload, \\Ultra\\DecisionEngine_Compiler\\Tests\\EntityPairConfirmed::class))")
        ->not->toContain('(function () use ($state, $event): void {');

    expect($state)->toBeInstanceOf(ExactSubscriptionState::class)
        ->and($state->statuses)->toBe(['matched']);
});

test('generated loaders reject missing, extra, and duplicate tag values', function () {
    $container = createEngineContainer('validation');
    $engine = $container->get(Engine::class);

    expect(fn () => $engine->load(
        SubscriptionState::class,
        new DomainIdentifier('course', 'course-1'),
    ))->toThrow(MissingTagValueException::class);

    expect(fn () => $engine->load(
        SubscriptionState::class,
        new DomainIdentifier('course', 'course-1'),
        new DomainIdentifier('student', 'student-1'),
        new DomainIdentifier('faculty', 'faculty-1'),
    ))->toThrow(UnexpectedTagValueException::class);

    expect(fn () => $engine->load(
        SubscriptionState::class,
        new DomainIdentifier('course', 'course-1'),
        new DomainIdentifier('course', 'course-1b'),
        new DomainIdentifier('student', 'student-1'),
    ))->toThrow(DuplicateTagValueException::class);
});

test('engine throws for unknown event-sourced entities', function () {
    $engine = new Engine([]);

    expect(fn () => $engine->load(NotAnEntityState::class))->toThrow(UnknownEventSourcedEntityException::class);
});
