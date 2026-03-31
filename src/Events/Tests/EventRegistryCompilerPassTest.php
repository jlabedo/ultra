<?php

declare(strict_types=1);

namespace Ultra\Events\Tests;

use Symfony\Component\DependencyInjection\ChildDefinition;
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

test('compiler pass registers event names from tagged resource metadata', function () {
    $container = new ContainerBuilder();
    $container->setDefinition(
        EventRegistryCompilerPassTestEvent::class,
        (new Definition(EventRegistryCompilerPassTestEvent::class))
            ->addResourceTag(EventsBundle::EVENT_TAG, ['name' => 'faculty.course_created'])
    );

    (new EventRegistryCompilerPass())->process($container);

    expect($container->hasDefinition(EventRegistryInterface::class))->toBeTrue()
        ->and($container->getDefinition(EventRegistryInterface::class)->getArgument(0))
        ->toBe([
            'faculty.course_created' => EventRegistryCompilerPassTestEvent::class,
        ])
        ->and($container->getDefinition(EventRegistryInterface::class)->getArgument(1))
        ->toBe([])
        ->and($container->getDefinition(EventRegistryInterface::class)->getArgument(2))
        ->toBe([]);
});

test('event bundle autoconfiguration preserves the event name when collecting domain identifiers', function () {
    $container = new ContainerBuilder();
    (new EventsBundle())->build($container);

    $definition = new ChildDefinition('');
    $configurator = $container->getAttributeAutoconfigurators()[Event::class][0];
    $configurator(
        $definition,
        new Event('faculty.course_created'),
        new \ReflectionClass(EventRegistryBundleTaggedEvent::class),
    );

    expect($definition->getTag(EventsBundle::EVENT_TAG))->toBe([[
        'name' => 'faculty.course_created',
        'tags' => ['faculty' => 'facultyId'],
    ]]);
});

test('compiler pass injects visibility-aware domain identifier metadata', function () {
    $container = new ContainerBuilder();
    $container->setDefinition(
        EventRegistryPrivateTaggedEvent::class,
        (new Definition(EventRegistryPrivateTaggedEvent::class))
            ->addResourceTag(EventsBundle::EVENT_TAG, ['name' => 'faculty.course_archived'])
    );

    (new EventRegistryCompilerPass())->process($container);

    expect($container->getDefinition(EventRegistryInterface::class)->getArgument(1))
        ->toBe([
            EventRegistryPrivateTaggedEvent::class => [
                'faculty' => 'facultyId',
            ],
        ])
        ->and($container->getDefinition(EventRegistryInterface::class)->getArgument(2))
        ->toBe([
            EventRegistryPrivateTaggedEvent::class => true,
        ]);
});
