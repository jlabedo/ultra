<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Ultra\Mediator\Attribute\AdviceDescriptor;
use Ultra\Mediator\Attribute\AspectAttribute;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\MediatorInterface;
use Ultra\Mediator_Compiler\MediatorCompilerPass;

// ---------------------------------------------------------------------------
// Shared temp directory for this test run
// ---------------------------------------------------------------------------

$testOutputDir = dirname(__DIR__, 3) . '/var/mediator_compiler_tests';

// ---------------------------------------------------------------------------
// Message fixtures
// ---------------------------------------------------------------------------

final readonly class HelloMessage
{
    public function __construct(public string $name)
    {
    }
}

final readonly class GoodbyeMessage
{
    public function __construct(public string $name)
    {
    }
}

final readonly class PingMessage
{
}

final readonly class AuditedMessage
{
    public function __construct(public string $payload)
    {
    }
}

final readonly class WrappedMessage
{
    public function __construct(public string $payload)
    {
    }
}

final readonly class DoubleWrappedMessage
{
    public function __construct(public string $payload)
    {
    }
}

// ---------------------------------------------------------------------------
// Service fixtures (plain collaborators and aspect services)
// ---------------------------------------------------------------------------

final class GreeterService
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }
}

final class TestLogger
{
    /** @var list<object> */
    public array $logged = [];

    public function log(object $message, array $context): void
    {
        $this->logged[] = $message;
    }
}

final class TestTransactionManager
{
    public int $calls = 0;

    public function execute(callable $proceed, object $message, array $context): mixed
    {
        $this->calls++;
        return $proceed($message, $context);
    }
}

final class OuterAspectService
{
    /** @var list<string> */
    public array $log = [];

    public function wrap(callable $proceed, object $message, array $context): mixed
    {
        $this->log[] = 'outer-before';
        $result = $proceed($message, $context);
        $this->log[] = 'outer-after';
        return $result;
    }
}

final class InnerAspectService
{
    /** @var list<string> */
    public array $log = [];

    public function wrap(callable $proceed, object $message, array $context): mixed
    {
        $this->log[] = 'inner-before';
        $result = $proceed($message, $context);
        $this->log[] = 'inner-after';
        return $result;
    }
}

// ---------------------------------------------------------------------------
// Aspect-attribute fixtures
// ---------------------------------------------------------------------------

#[\Attribute(\Attribute::TARGET_METHOD)]
final class LogBefore implements AspectAttribute
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::before(TestLogger::class, 'log');
    }
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Transactional implements AspectAttribute
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(TestTransactionManager::class, 'execute');
    }
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class OuterAspect implements AspectAttribute
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(OuterAspectService::class, 'wrap');
    }
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class InnerAspect implements AspectAttribute
{
    public function getAdviceDescriptor(): AdviceDescriptor
    {
        return AdviceDescriptor::around(InnerAspectService::class, 'wrap');
    }
}

// ---------------------------------------------------------------------------
// Handler fixtures
// ---------------------------------------------------------------------------

final class SalutationHandler
{
    #[Handles]
    public function hello(HelloMessage $msg): string
    {
        return 'Hi, ' . $msg->name;
    }

    #[Handles]
    public function goodbye(GoodbyeMessage $msg): string
    {
        return 'Bye, ' . $msg->name;
    }

    /** Regular method — must be ignored */
    public function helper(): void
    {
    }
}

final class GreetWithServiceHandler
{
    #[Handles]
    public function ping(PingMessage $_msg, GreeterService $greeter): string
    {
        return $greeter->greet('pong');
    }
}

final class AuditedHandler
{
    #[Handles]
    #[LogBefore]
    public function handle(AuditedMessage $msg): string
    {
        return 'handled: ' . $msg->payload;
    }
}

final class WrappedHandler
{
    #[Handles]
    #[Transactional]
    public function handle(WrappedMessage $msg): string
    {
        return 'wrapped: ' . $msg->payload;
    }
}

final class DoubleWrappedHandler
{
    #[Handles]
    #[OuterAspect]
    #[InnerAspect]
    public function handle(DoubleWrappedMessage $msg): string
    {
        return 'double: ' . $msg->payload;
    }
}

// ---------------------------------------------------------------------------
// MediatorCompilerPass — integration tests
// (ContainerBuilder wiring; no filesystem class loading)
// ---------------------------------------------------------------------------

test('compiler pass registers MediatorInterface definition in the container', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, (new Definition(SalutationHandler::class))->addTag('mediator.handler'));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassTest1');
    $pass->process($container);

    expect($container->hasDefinition(MediatorInterface::class))->toBeTrue();
    expect($container->hasDefinition('ultra.mediator.locator'))->toBeTrue();
});

test('compiler pass does nothing when no handlers are tagged', function () use ($testOutputDir) {
    $container = new ContainerBuilder();

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassTest2');
    $pass->process($container);

    expect($container->hasDefinition(MediatorInterface::class))->toBeFalse();
});

test('compiler pass throws on duplicate handlers for the same message', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition('handler_a', (new Definition(SalutationHandler::class))->addTag('mediator.handler'));
    $container->setDefinition('handler_b', (new Definition(SalutationHandler::class))->addTag('mediator.handler'));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassTest3');

    expect(fn() => $pass->process($container))->toThrow(\LogicException::class);
});

test('compiler pass adds aspect service IDs to the service locator', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(AuditedHandler::class, (new Definition(AuditedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(TestLogger::class, new Definition(TestLogger::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorPassAop1');
    $pass->process($container);

    $locatorArgs = $container->getDefinition('ultra.mediator.locator')->getArgument(0);

    expect($locatorArgs)
        ->toHaveKey(AuditedHandler::class)
        ->toHaveKey(TestLogger::class);
});

// ---------------------------------------------------------------------------
// End-to-end: generated class actually dispatches correctly
// ---------------------------------------------------------------------------

test('generated mediator routes messages to the correct handler method', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, (new Definition(SalutationHandler::class))->addTag('mediator.handler'));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorE2E1');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorE2E1.php';

    $locator = new ServiceLocator([
        SalutationHandler::class => fn() => new SalutationHandler(),
    ]);

    $mediator = new \CompiledMediatorE2E1($locator);

    expect($mediator->send(new HelloMessage('Alice')))->toBe('Hi, Alice')
        ->and($mediator->send(new GoodbyeMessage('Bob')))->toBe('Bye, Bob');
});

test('generated mediator injects services from the locator into handler methods', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(GreetWithServiceHandler::class, (new Definition(GreetWithServiceHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(GreeterService::class, new Definition(GreeterService::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorE2E2');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorE2E2.php';

    $locator = new ServiceLocator([
        GreetWithServiceHandler::class => fn() => new GreetWithServiceHandler(),
        GreeterService::class => fn() => new GreeterService(),
    ]);

    $mediator = new \CompiledMediatorE2E2($locator);

    expect($mediator->send(new PingMessage()))->toBe('Hello, pong!');
});

test('generated mediator throws NoHandlerFound for unregistered messages', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(SalutationHandler::class, (new Definition(SalutationHandler::class))->addTag('mediator.handler'));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorE2E3');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorE2E3.php';

    $locator = new ServiceLocator([
        SalutationHandler::class => fn() => new SalutationHandler(),
    ]);
    $mediator = new \CompiledMediatorE2E3($locator);

    expect(fn() => $mediator->send(new PingMessage()))
        ->toThrow(\Ultra\Mediator\Exception\NoHandlerFound::class);
});

test('generated mediator calls before-advice then invokes the handler', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(AuditedHandler::class, (new Definition(AuditedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(TestLogger::class, new Definition(TestLogger::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAop1');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorAop1.php';

    $logger = new TestLogger();
    $locator = new ServiceLocator([
        AuditedHandler::class => fn() => new AuditedHandler(),
        TestLogger::class => fn() => $logger,
    ]);

    $mediator = new \CompiledMediatorAop1($locator);
    $message = new AuditedMessage('test');
    $result = $mediator->send($message);

    expect($result)->toBe('handled: test')
        ->and($logger->logged)->toHaveCount(1)
        ->and($logger->logged[0])->toBe($message);
});

test('generated mediator calls around-advice wrapping the handler', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(WrappedHandler::class, (new Definition(WrappedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(TestTransactionManager::class, new Definition(TestTransactionManager::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAop2');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorAop2.php';

    $txManager = new TestTransactionManager();
    $locator = new ServiceLocator([
        WrappedHandler::class => fn() => new WrappedHandler(),
        TestTransactionManager::class => fn() => $txManager,
    ]);

    $mediator = new \CompiledMediatorAop2($locator);
    $result = $mediator->send(new WrappedMessage('test'));

    expect($result)->toBe('wrapped: test')
        ->and($txManager->calls)->toBe(1);
});

test('generated mediator executes multiple around-advices in declaration order', function () use ($testOutputDir) {
    $container = new ContainerBuilder();
    $container->setDefinition(DoubleWrappedHandler::class, (new Definition(DoubleWrappedHandler::class))->addTag('mediator.handler'));
    $container->setDefinition(OuterAspectService::class, new Definition(OuterAspectService::class));
    $container->setDefinition(InnerAspectService::class, new Definition(InnerAspectService::class));

    $pass = new MediatorCompilerPass($testOutputDir, 'CompiledMediatorAop3');
    $pass->process($container);

    require_once $testOutputDir . '/CompiledMediatorAop3.php';

    $outer = new OuterAspectService();
    $inner = new InnerAspectService();
    $locator = new ServiceLocator([
        DoubleWrappedHandler::class => fn() => new DoubleWrappedHandler(),
        OuterAspectService::class => fn() => $outer,
        InnerAspectService::class => fn() => $inner,
    ]);

    $mediator = new \CompiledMediatorAop3($locator);
    $result = $mediator->send(new DoubleWrappedMessage('test'));

    expect($result)->toBe('double: test')
        // First-declared (#[OuterAspect]) wraps everything
        ->and($outer->log)->toBe(['outer-before', 'outer-after'])
        // Second-declared (#[InnerAspect]) is nested inside outer
        ->and($inner->log)->toBe(['inner-before', 'inner-after']);
});
