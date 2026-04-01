<?php

declare(strict_types=1);

namespace Ultra\Mediator_Bundle\Tests;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\Attribute\SelfHandler;
use Ultra\Mediator\MediatorInterface;
use Ultra\Mediator_Bundle\MediatorBundle;
use Ultra\Mediator_Bundle\TagNames;

final class MediatorBundleTestGreeter
{
    public function greet(string $name): string
    {
        return 'Hello, '.$name.'!';
    }
}

final readonly class BundledSelfGreetingMessage
{
    public function __construct(public string $name) {}

    #[SelfHandler]
    public function greet(MediatorBundleTestGreeter $greeter): string
    {
        return $greeter->greet($this->name);
    }
}

final readonly class BundledRegularGreetingMessage
{
    public function __construct(public string $name) {}
}

final class BundledRegularGreetingHandler
{
    #[Handles]
    public function greet(BundledRegularGreetingMessage $message): string
    {
        return 'Hi, '.$message->name;
    }
}

test('mediator bundle tags SelfHandler methods as self handlers', function () {
    $container = new ContainerBuilder();
    $container->setParameter('kernel.build_dir', dirname(__DIR__, 3).'/var/mediator_bundle_tests');
    new MediatorBundle()->build($container);

    $definition = new ChildDefinition('');
    $configurator = $container->getAttributeAutoconfigurators()[SelfHandler::class][0];
    $configurator(
        $definition,
        new SelfHandler(),
        new \ReflectionMethod(BundledSelfGreetingMessage::class, 'greet'),
    );

    expect($definition->getTag(TagNames::SELF_HANDLER))->toBe([[]])
        ->and($definition->getTag(TagNames::HANDLER))->toBe([])
    ;
});

test('mediator bundle keeps regular Handles methods on the regular handler tag', function () {
    $container = new ContainerBuilder();
    $container->setParameter('kernel.build_dir', dirname(__DIR__, 3).'/var/mediator_bundle_tests');
    new MediatorBundle()->build($container);

    $definition = new ChildDefinition('');
    $configurator = $container->getAttributeAutoconfigurators()[Handles::class][0];
    $configurator(
        $definition,
        new Handles(),
        new \ReflectionMethod(BundledRegularGreetingHandler::class, 'greet'),
    );

    expect($definition->getTag(TagNames::HANDLER))->toBe([[]])
        ->and($definition->getTag(TagNames::SELF_HANDLER))->toBe([])
    ;
});

test('mediator bundle autodiscovers autoconfigured self handlers', function () {
    $container = new ContainerBuilder();
    $container->setParameter('kernel.build_dir', dirname(__DIR__, 3).'/var/mediator_bundle_tests');
    new MediatorBundle()->build($container);

    $container->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
    $container->register(BundledSelfGreetingMessage::class, BundledSelfGreetingMessage::class)
        ->setAutoconfigured(true)
    ;
    $container->register(MediatorBundleTestGreeter::class, MediatorBundleTestGreeter::class);

    $container->compile();

    /** @var MediatorInterface $mediator */
    $mediator = $container->get('test.mediator');

    expect($mediator->send(new BundledSelfGreetingMessage('Alice')))->toBe('Hello, Alice!');
});

test('mediator bundle uses a unique generated class per build dir', function () {
    $first = new ContainerBuilder();
    $first->setParameter('kernel.build_dir', dirname(__DIR__, 3).'/var/mediator_bundle_tests_a');
    new MediatorBundle()->build($first);
    $first->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
    $first->register(BundledSelfGreetingMessage::class, BundledSelfGreetingMessage::class)
        ->setAutoconfigured(true)
    ;
    $first->register(MediatorBundleTestGreeter::class, MediatorBundleTestGreeter::class);
    $first->compile();

    $second = new ContainerBuilder();
    $second->setParameter('kernel.build_dir', dirname(__DIR__, 3).'/var/mediator_bundle_tests_b');
    new MediatorBundle()->build($second);
    $second->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
    $second->register(BundledSelfGreetingMessage::class, BundledSelfGreetingMessage::class)
        ->setAutoconfigured(true)
    ;
    $second->register(MediatorBundleTestGreeter::class, MediatorBundleTestGreeter::class);
    $second->compile();

    expect($first->getParameter('ultra_mediator.generated_class'))
        ->not->toBe($second->getParameter('ultra_mediator.generated_class'));

    /** @var MediatorInterface $firstMediator */
    $firstMediator = $first->get('test.mediator');
    /** @var MediatorInterface $secondMediator */
    $secondMediator = $second->get('test.mediator');

    expect($firstMediator->send(new BundledSelfGreetingMessage('Alice')))->toBe('Hello, Alice!')
        ->and($secondMediator->send(new BundledSelfGreetingMessage('Bob')))->toBe('Hello, Bob!')
    ;
});
