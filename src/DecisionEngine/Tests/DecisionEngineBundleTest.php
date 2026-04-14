<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\DecisionEngine\Attribute\EventSourcedEntity;
use Ultra\DecisionEngine\Attribute\EventSourcingHandler;
use Ultra\DecisionEngine\DecisionEngineBundle;
use Ultra\DecisionEngine\Tests\Support\DecisionEngineTestContainer;
use Ultra\Events\Attribute\DomainIdentifier as DomainIdentifierAttribute;
use Ultra\Events\Attribute\Event;
use Ultra\Events\CompiledEventRegistry;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingBundle;

const DECISION_ENGINE_BUNDLE_TEST_BUILD_DIR = __DIR__.'/../../../var/decision_engine_bundle_tests';

#[Event('decision_engine_bundle.course_created')]
final readonly class CourseCreatedForDecisionEngineBundleTest
{
    public function __construct(
        #[DomainIdentifierAttribute('course')]
        public string $courseId,
        public string $name,
    ) {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CourseStateForDecisionEngineBundleTest
{
    public ?string $name = null;

    #[EventSourcingHandler]
    public function onCourseCreated(CourseCreatedForDecisionEngineBundleTest $event): void
    {
        $this->name = $event->name;
    }
}

final readonly class CreateCourseForDecisionEngineBundleTest
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseStateForDecisionEngineBundleTest $course): array
    {
        if (null !== $course->name) {
            throw new \LogicException('Course already exists.');
        }

        return [new CourseCreatedForDecisionEngineBundleTest($this->courseId, $this->name)];
    }
}

final class RecordCourseCreatedForDecisionEngineBundleTest
{
    #[EventHandler]
    public function onCourseCreated(CourseCreatedForDecisionEngineBundleTest $event): void {}
}

/**
 * @internal
 *
 * @coversNothing
 */
final class DecisionEngineBundleTest extends TestCase
{
    public function test_it_wires_the_decision_engine_into_the_message_handling_bundle(): void
    {
        $container = $this->createContainer();

        $mediator = $container->mediator();
        $events = $mediator->send(new CreateCourseForDecisionEngineBundleTest('course-1', 'Math'));

        self::assertEquals(
            [new CourseCreatedForDecisionEngineBundleTest('course-1', 'Math')],
            $events,
        );

        $engine = $container->engine();
        $state = $engine->load(
            CourseStateForDecisionEngineBundleTest::class,
            new DomainIdentifier('course', 'course-1'),
        );

        self::assertSame('Math', $state->name);
    }

    private function createContainer(): DecisionEngineTestContainer
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', DECISION_ENGINE_BUNDLE_TEST_BUILD_DIR);
        $container->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);

        $container->register(UltraNormalizer::class, UltraNormalizer::class)
            ->setArguments([DECISION_ENGINE_BUNDLE_TEST_BUILD_DIR.'/normalizer'])
        ;

        $container->register(InMemoryEventStore::class, InMemoryEventStore::class)
        ;
        $container->setAlias(EventStore::class, InMemoryEventStore::class);

        $container->register(CompiledEventRegistry::class, CompiledEventRegistry::class)
            ->setArguments([[
                'decision_engine_bundle.course_created' => CourseCreatedForDecisionEngineBundleTest::class,
            ], [
                CourseCreatedForDecisionEngineBundleTest::class => [
                    'course' => 'courseId',
                ],
            ]])
        ;
        $container->setAlias(EventRegistryInterface::class, CompiledEventRegistry::class);

        $container->register(CourseCreatedForDecisionEngineBundleTest::class, CourseCreatedForDecisionEngineBundleTest::class)
            ->setAutowired(false)
            ->addResourceTag('ultra_event_bus.event')
        ;
        $container->register(CourseStateForDecisionEngineBundleTest::class, CourseStateForDecisionEngineBundleTest::class)
            ->setAutoconfigured(true)
            ->setAutowired(true)
        ;
        $container->register(CreateCourseForDecisionEngineBundleTest::class, CreateCourseForDecisionEngineBundleTest::class)
            ->setAutoconfigured(true)
            ->setAutowired(true)
        ;
        $container->register(RecordCourseCreatedForDecisionEngineBundleTest::class, RecordCourseCreatedForDecisionEngineBundleTest::class)
            ->setAutoconfigured(true)
            ->setAutowired(true)
        ;

        new MessageHandlingBundle()->build($container);
        new DecisionEngineBundle()->build($container);
        $container->compile();

        return new DecisionEngineTestContainer($container);
    }
}
