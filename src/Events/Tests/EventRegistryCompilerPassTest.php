<?php

declare(strict_types=1);

namespace Ultra\Events\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;
use Ultra\Events\EventRegistryCompilerPass;
use Ultra\Events\EventRegistryInterface;
use Ultra\Events\EventsBundle;

final readonly class EventRegistryCompilerPassTestEvent {}

#[Event('faculty.course_created')]
final readonly class EventRegistryBundleTaggedEvent
{
    public function __construct(
        #[DomainIdentifier('faculty')]
        public string $facultyId,
    ) {}
}

#[Event('faculty.course_archived')]
final class EventRegistryPrivateTaggedEvent
{
    #[DomainIdentifier('faculty')]
    private string $facultyId;

    public function __construct(string $facultyId)
    {
        $this->facultyId = $facultyId;
    }
}

/**
 * @internal
 */
#[CoversClass(EventRegistryCompilerPass::class)]
final class EventRegistryCompilerPassTest extends TestCase
{
    public function test_it_registers_event_names_from_tagged_resource_metadata(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(
            EventRegistryCompilerPassTestEvent::class,
            new Definition(EventRegistryCompilerPassTestEvent::class)
                ->addResourceTag(EventsBundle::EVENT_TAG, ['name' => 'faculty.course_created'])
        );

        $registry = $this->compileRegistry($container, static function (ContainerBuilder $container): void {
            new EventRegistryCompilerPass()->process($container);
        });

        self::assertSame(
            ['faculty.course_created' => EventRegistryCompilerPassTestEvent::class],
            $registry->all(),
        );
        self::assertSame(
            EventRegistryCompilerPassTestEvent::class,
            $registry->getClass('faculty.course_created'),
        );
        self::assertSame(
            'faculty.course_created',
            $registry->getName(EventRegistryCompilerPassTestEvent::class),
        );
    }

    public function test_it_compiles_event_names_and_domain_identifiers_from_bundle_autoconfiguration(): void
    {
        $container = new ContainerBuilder();
        new EventsBundle()->build($container);

        $container->register(EventRegistryBundleTaggedEvent::class, EventRegistryBundleTaggedEvent::class)
            ->setAutoconfigured(true)
            ->setAutowired(false)
        ;
        $container->register(EventRegistryPrivateTaggedEvent::class, EventRegistryPrivateTaggedEvent::class)
            ->setAutoconfigured(true)
            ->setAutowired(false)
        ;

        $registry = $this->compileRegistry($container);

        self::assertSame(
            EventRegistryBundleTaggedEvent::class,
            $registry->getClass('faculty.course_created'),
        );
        self::assertSame(
            EventRegistryPrivateTaggedEvent::class,
            $registry->getClass('faculty.course_archived'),
        );
        self::assertSame(
            ['faculty' => 'faculty-public'],
            $registry->getDomainIdentifiers(new EventRegistryBundleTaggedEvent('faculty-public')),
        );
        self::assertSame(
            ['faculty' => 'faculty-private'],
            $registry->getDomainIdentifiers(new EventRegistryPrivateTaggedEvent('faculty-private')),
        );
    }

    /**
     * @param null|\Closure(ContainerBuilder): void $configure
     */
    private function compileRegistry(ContainerBuilder $container, ?\Closure $configure = null): EventRegistryInterface
    {
        if ($configure instanceof \Closure) {
            $configure($container);
        }

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->hasDefinition(EventRegistryInterface::class)) {
                    $container->getDefinition(EventRegistryInterface::class)->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, -1000);

        $container->compile();

        // @var EventRegistryInterface $registry
        return $container->get(EventRegistryInterface::class);
    }
}
