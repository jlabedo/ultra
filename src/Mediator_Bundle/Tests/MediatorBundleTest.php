<?php

declare(strict_types=1);

namespace Ultra\Mediator_Bundle\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingBundle;
use Ultra\MessageHandling_Compiler\MessageRouterCompilerPass;

const MEDIATOR_BUNDLE_TEST_OUTPUT_DIR = __DIR__.'/../../../var/mediator_bundle_tests';

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

test('mediator bundle generates unique class names for different scenarios', function () {
    $first = new ContainerBuilder();
    $first->setParameter('kernel.build_dir', MEDIATOR_BUNDLE_TEST_OUTPUT_DIR.'/a');
    new MessageHandlingBundle()->build($first);
    $first->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
    $first->register(BundledSelfGreetingMessage::class, BundledSelfGreetingMessage::class)
        ->setAutoconfigured(true)
    ;
    $first->register(MediatorBundleTestGreeter::class, MediatorBundleTestGreeter::class);
    $first->addCompilerPass(new MessageRouterCompilerPass(
        MEDIATOR_BUNDLE_TEST_OUTPUT_DIR.'/a',
    ));
    $first->compile();

    $second = new ContainerBuilder();
    $second->setParameter('kernel.build_dir', MEDIATOR_BUNDLE_TEST_OUTPUT_DIR.'/b');
    new MessageHandlingBundle()->build($second);
    $second->setAlias('test.mediator', MediatorInterface::class)->setPublic(true);
    $second->register(BundledSelfGreetingMessage::class, BundledSelfGreetingMessage::class)
        ->setAutoconfigured(true)
    ;
    $second->register(MediatorBundleTestGreeter::class, MediatorBundleTestGreeter::class);
    $second->addCompilerPass(new MessageRouterCompilerPass(
        MEDIATOR_BUNDLE_TEST_OUTPUT_DIR.'/b',
    ));
    $second->compile();

    /** @var MediatorInterface $firstMediator */
    $firstMediator = $first->get('test.mediator');
    /** @var MediatorInterface $secondMediator */
    $secondMediator = $second->get('test.mediator');

    expect($firstMediator->send(new BundledSelfGreetingMessage('Alice')))->toBe('Hello, Alice!')
        ->and($secondMediator->send(new BundledSelfGreetingMessage('Bob')))->toBe('Hello, Bob!')
    ;
});
