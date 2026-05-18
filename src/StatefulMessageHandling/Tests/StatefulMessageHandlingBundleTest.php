<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\EventSourcedStateBundle;
use Ultra\StatefulMessageHandling\StatefulMessageHandlingBundle;
use Ultra\StatefulMessageHandling\Tests\Support\StatefulMessageHandlingTestContainer;
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

const STATEFUL_MESSAGE_HANDLING_BUNDLE_TEST_BUILD_DIR = __DIR__.'/../../../var/stateful_message_handling_bundle_tests';

#[Event('stateful_message_handling_bundle.course_created')]
final readonly class CourseCreatedForStatefulMessageHandlingBundleTest
{
    public function __construct(
        #[DomainIdentifierAttribute('course')]
        public string $courseId,
        public string $name,
    ) {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CourseStateForStatefulMessageHandlingBundleTest
{
    public ?string $name = null;

    #[EventSourcingHandler]
    public function onCourseCreated(CourseCreatedForStatefulMessageHandlingBundleTest $event): void
    {
        $this->name = $event->name;
    }
}

final readonly class CreateCourseForStatefulMessageHandlingBundleTest
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseStateForStatefulMessageHandlingBundleTest $course): array
    {
        if (null !== $course->name) {
            throw new \LogicException('Course already exists.');
        }

        return [new CourseCreatedForStatefulMessageHandlingBundleTest($this->courseId, $this->name)];
    }
}

final class RecordCourseCreatedForStatefulMessageHandlingBundleTest
{
    #[EventHandler]
    public function onCourseCreated(CourseCreatedForStatefulMessageHandlingBundleTest $event): void {}
}

/**
 * @internal
 *
 * @coversNothing
 */
final class StatefulMessageHandlingBundleTest extends TestCase
{
    public function test_it_wires_the_stateful_message_handling_into_the_message_handling_bundle(): void
    {
        $container = $this->createContainer();

        $mediator = $container->mediator();
        $events = $mediator->send(new CreateCourseForStatefulMessageHandlingBundleTest('course-1', 'Math'));

        self::assertEquals(
            [new CourseCreatedForStatefulMessageHandlingBundleTest('course-1', 'Math')],
            $events,
        );

        $stateStore = $container->eventSourcedStateStore();
        $state = $stateStore->load(
            CourseStateForStatefulMessageHandlingBundleTest::class,
            new DomainIdentifier('course', 'course-1'),
        );

        self::assertSame('Math', $state->name);
    }

    private function createContainer(): StatefulMessageHandlingTestContainer
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', STATEFUL_MESSAGE_HANDLING_BUNDLE_TEST_BUILD_DIR);
        $container->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);

        $container->register(UltraNormalizer::class, UltraNormalizer::class)
            ->setArguments([STATEFUL_MESSAGE_HANDLING_BUNDLE_TEST_BUILD_DIR.'/normalizer'])
        ;

        $container->register(InMemoryEventStore::class, InMemoryEventStore::class);
        $container->setAlias(EventStore::class, InMemoryEventStore::class);

        $container->register(CompiledEventRegistry::class, CompiledEventRegistry::class)
            ->setArguments([[
                'stateful_message_handling_bundle.course_created' => CourseCreatedForStatefulMessageHandlingBundleTest::class,
            ], [
                CourseCreatedForStatefulMessageHandlingBundleTest::class => [
                    'course' => 'courseId',
                ],
            ]])
        ;
        $container->setAlias(EventRegistryInterface::class, CompiledEventRegistry::class);

        $container->register(CourseCreatedForStatefulMessageHandlingBundleTest::class, CourseCreatedForStatefulMessageHandlingBundleTest::class)
            ->setAutowired(false)
            ->addResourceTag('ultra_event_bus.event')
        ;
        $container->register(CourseStateForStatefulMessageHandlingBundleTest::class, CourseStateForStatefulMessageHandlingBundleTest::class)
            ->setAutoconfigured(true)
            ->setAutowired(true)
        ;
        $container->register(CreateCourseForStatefulMessageHandlingBundleTest::class, CreateCourseForStatefulMessageHandlingBundleTest::class)
            ->setAutoconfigured(true)
            ->setAutowired(true)
        ;
        $container->register(RecordCourseCreatedForStatefulMessageHandlingBundleTest::class, RecordCourseCreatedForStatefulMessageHandlingBundleTest::class)
            ->setAutoconfigured(true)
            ->setAutowired(true)
        ;

        new MessageHandlingBundle()->build($container);
        new EventSourcedStateBundle()->build($container);
        new StatefulMessageHandlingBundle()->build($container);
        $container->compile();

        return new StatefulMessageHandlingTestContainer($container);
    }
}
