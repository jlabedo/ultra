<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\Events\EventRegistryCompilerPass;
use Ultra\Events\EventRegistryInterface;
use Ultra\Events\EventsExtension;
use Ultra\EventStore\Tests\Support\MetadataNamedEvent;
use Ultra\EventStore\Tests\Support\PrivateIdentifiedEvent;
use Ultra\EventStore\Tests\Support\ProtectedIdentifiedEvent;
use Ultra\EventStore\Tests\Support\PublicIdentifiedEvent;

require_once __DIR__ . '/Support/EventIdentityFixtures.php';

/** @internal */
#[CoversNamespace('Ultra\Events')]
final class EventIdentityTest extends TestCase
{
    #[DataProvider('eventNames')]
    public function test_explicit_resource_names_are_available_in_both_registry_directions(string $name): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MetadataNamedEvent::class, new Definition(
            MetadataNamedEvent::class,
        )->addResourceTag(EventsExtension::EVENT_TAG, ['name' => $name]));
        new EventRegistryCompilerPass()->process($container);
        $registry = $this->compileRegistry($container);

        static::assertSame([$name => MetadataNamedEvent::class], $registry->all());
        static::assertSame(MetadataNamedEvent::class, $registry->getClass($name));
        static::assertSame($name, $registry->getName(MetadataNamedEvent::class));
        static::assertSame([], $registry->getDomainIdentifiers(new MetadataNamedEvent()));
        static::assertNull($registry->getClass('unknown'));
        static::assertNull($registry->getName(\stdClass::class));
    }

    /** @return iterable<string, array{string}> */
    public static function eventNames(): iterable
    {
        yield 'text' => ['explicit.event'];
        yield 'empty string' => [''];
        yield 'zero' => ['0'];
        yield 'numeric string' => ['17'];
    }

    public function test_autoconfiguration_compiles_names_and_identifiers_for_all_property_visibilities(): void
    {
        $container = new ContainerBuilder();
        new EventsExtension()->build($container);
        foreach ([
            PublicIdentifiedEvent::class,
            ProtectedIdentifiedEvent::class,
            PrivateIdentifiedEvent::class,
        ] as $class) {
            $container->register($class, $class)->setAutoconfigured(true)->setAutowired(false);
        }
        $registry = $this->compileRegistry($container);

        static::assertEquals(
            [
                'course.created' => PublicIdentifiedEvent::class,
                'course.archived' => PrivateIdentifiedEvent::class,
                'course.renamed' => ProtectedIdentifiedEvent::class,
            ],
            $registry->all(),
        );
        foreach ([
            ['course.created', new PublicIdentifiedEvent('public'), ['course' => 'public', 'faculty' => 'f1']],
            ['course.archived', new PrivateIdentifiedEvent('private'), ['course' => 'private']],
            ['course.renamed', new ProtectedIdentifiedEvent('protected'), ['course' => 'protected', 'faculty' => 'f1']],
        ] as [$name, $event, $identifiers]) {
            static::assertSame($event::class, $registry->getClass($name));
            static::assertSame($name, $registry->getName($event::class));
            static::assertEquals($identifiers, $registry->getDomainIdentifiers($event));
        }
    }

    public function test_an_event_name_cannot_identify_two_different_classes(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MetadataNamedEvent::class, new Definition(MetadataNamedEvent::class)
            ->addResourceTag(EventsExtension::EVENT_TAG, ['name' => 'explicit.event'])
            ->addResourceTag(EventsExtension::EVENT_TAG, ['name' => 'explicit.event']));
        new EventRegistryCompilerPass()->process($container);
        static::assertSame(['explicit.event' => MetadataNamedEvent::class], $this->compileRegistry($container)->all());

        $conflict = new ContainerBuilder();
        foreach ([MetadataNamedEvent::class, PublicIdentifiedEvent::class] as $class) {
            $conflict->setDefinition($class, new Definition($class)->addResourceTag(EventsExtension::EVENT_TAG, [
                'name' => 'explicit.event',
            ]));
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate #[Event] name "explicit.event"');
        new EventRegistryCompilerPass()->process($conflict);
    }

    /** @param array<string, mixed> $tag */
    #[DataProvider('invalidEventResources')]
    public function test_invalid_event_resources_are_rejected_during_compilation(
        ?string $class,
        array $tag,
        string $message,
    ): void {
        $container = new ContainerBuilder();
        $container->setDefinition('invalid.event', new Definition($class)->addResourceTag(
            EventsExtension::EVENT_TAG,
            $tag,
        ));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        new EventRegistryCompilerPass()->process($container);
    }

    /** @return iterable<string, array{?string, array<string, mixed>, string}> */
    public static function invalidEventResources(): iterable
    {
        yield 'missing class' => [null, [], 'must have a class and not be abstract'];
        yield 'unknown class' => ['UnknownEventResource', [], 'must have an existing class'];
        yield 'non-string name' => [MetadataNamedEvent::class, ['name' => 42], 'must have a string name'];
    }

    private function compileRegistry(ContainerBuilder $container): EventRegistryInterface
    {
        $container->addCompilerPass(
            new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    $container->getDefinition(EventRegistryInterface::class)->setPublic(true);
                }
            },
            PassConfig::TYPE_BEFORE_REMOVING,
            -1000,
        );
        $container->compile();

        return $container->get(EventRegistryInterface::class);
    }
}
