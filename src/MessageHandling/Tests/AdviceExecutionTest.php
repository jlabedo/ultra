<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\SelfGreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_Compiler')]
final class AdviceExecutionTest extends MessageHandlingCompilerTestCase
{
    /** @return iterable<string, array{bool, ?bool}> */
    public static function outcomes(): iterable
    {
        yield 'success without around advice' => [false, null];
        yield 'failure without around advice' => [true, null];
        yield 'success with ProceedResult declaration' => [false, false];
        yield 'failure with ProceedResult declaration' => [true, false];
        yield 'success with mixed declaration' => [false, true];
        yield 'failure with mixed declaration' => [true, true];
    }

    #[DataProvider('outcomes')]
    public function test_advice_receives_the_handler_outcome_and_cleanup_runs_on_both_paths(
        bool $fails,
        ?bool $mixed,
    ): void {
        $probe = new OutcomeProbe();
        $failure = $fails ? new \RuntimeException('handler failed') : null;
        $message = new GreetingMessage('hello');
        $light = $this
            ->ultraLight()
            ->withService(new class($probe, $failure) {
                public function __construct(
                    private OutcomeProbe $probe,
                    private ?\Throwable $failure,
                ) {}

                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    $this->probe->entries[] = 'handler';
                    if (null !== $this->failure)
                        throw $this->failure;
                    return 'observed: ' . $message->name;
                }
            })
            ->withService(new class($probe) {
                public function __construct(
                    private OutcomeProbe $probe,
                ) {}

                #[Before]
                public function before(object $message, MessageContext $context): void
                {
                    $this->probe->message = $message;
                    $this->probe->entries[] = 'before';
                }

                #[AfterReturning]
                public function success(object $message, MessageContext $context, mixed $value): void
                {
                    $this->probe->value = $value;
                    $this->probe->entries[] = 'success';
                }

                #[AfterThrowing]
                public function failure(object $message, MessageContext $context, \Throwable $error): void
                {
                    $this->probe->failure = $error;
                    $this->probe->entries[] = 'failure';
                }

                #[After]
                public function cleanup(object $message, MessageContext $context): void
                {
                    $this->probe->entries[] = 'cleanup';
                }
            });
        if (null !== $mixed) {
            $light = $light->withService($mixed ? new MixedAroundOutcome($probe) : new TypedAroundOutcome($probe));
        }
        $mediator = $light->boot()->mediator();
        $prefix = null === $mixed ? ['before', 'handler'] : ['before', 'around', 'handler'];

        if ($fails) {
            try {
                $mediator->send($message);
                static::fail('Expected the handler failure.');
            } catch (\RuntimeException $caught) {
                static::assertSame($failure, $caught);
            }
            static::assertSame($failure, $probe->failure);
            static::assertNull($probe->value);
            static::assertSame([...$prefix, 'failure', 'cleanup'], $probe->entries);
        } else {
            static::assertSame('observed: hello', $mediator->send($message));
            static::assertSame('observed: hello', $probe->value);
            static::assertNull($probe->failure);
            static::assertSame([...$prefix, 'success', 'cleanup'], $probe->entries);
        }
        static::assertSame($message, $probe->message);
    }

    /** @return iterable<string, array{bool, mixed}> */
    public static function resultWrappers(): iterable
    {
        foreach ([false, true] as $wrapped) {
            $label = $wrapped ? 'with around' : 'without around';
            yield $label . ' string' => [$wrapped, 'observed: hello'];
            yield $label . ' business ProceedResult' => [
                $wrapped,
                new ProceedResult('business value', MessageContext::fromArray(['business' => true])),
            ];
        }
    }

    #[DataProvider('resultWrappers')]
    public function test_after_returning_receives_the_business_value_and_keeps_its_patch_in_the_result_context(
        bool $wrapped,
        mixed $value,
    ): void {
        $probe = new OutcomeProbe();
        $light = $this
            ->ultraLight()
            ->withService(new class($value) {
                public function __construct(
                    private mixed $value,
                ) {}

                #[Handles]
                public function handle(GreetingMessage $message): mixed
                {
                    return $this->value;
                }
            })
            ->withService(new class($probe) {
                public function __construct(
                    private OutcomeProbe $probe,
                ) {}

                #[AfterReturning]
                public function record(object $message, MessageContext $context, mixed $value): array
                {
                    $this->probe->value = $value;
                    return ['observed' => 'recorded'];
                }
            });
        if ($wrapped) {
            $light = $light->withService(new class($probe) {
                public function __construct(
                    private OutcomeProbe $probe,
                ) {}

                #[Around(priority: -10)]
                public function inspect(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $result = $proceed();
                    $this->probe->context = $result->context;
                    return $result;
                }
            });
        }
        static::assertSame($value, $light->boot()->mediator()->send(new GreetingMessage('hello')));
        static::assertSame($value, $probe->value);
        if ($wrapped) {
            static::assertSame('recorded', $probe->context?->get('observed'));
        }
    }

    public function test_self_handlers_receive_the_same_advice_chain(): void
    {
        $probe = new OutcomeProbe();
        $message = new SelfGreetingMessage('Dora');
        $mediator = $this
            ->ultraLight()
            ->withClass(SelfGreetingMessage::class)
            ->withService(new class($probe) {
                public function __construct(
                    private OutcomeProbe $probe,
                ) {}

                #[Before]
                public function before(object $message, MessageContext $context): void
                {
                    $this->probe->message = $message;
                }
            })
            ->boot()
            ->mediator();
        static::assertSame('Hello, Dora!', $mediator->send($message));
        static::assertSame($message, $probe->message);
    }
}

final class OutcomeProbe
{
    /** @var list<string> */
    public array $entries = [];
    public ?object $message = null;
    public ?MessageContext $context = null;
    public mixed $value = null;
    public ?\Throwable $failure = null;
}

final readonly class TypedAroundOutcome
{
    public function __construct(
        private OutcomeProbe $probe,
    ) {}

    #[Around]
    public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
    {
        $this->probe->entries[] = 'around';
        return $proceed();
    }
}

final readonly class MixedAroundOutcome
{
    public function __construct(
        private OutcomeProbe $probe,
    ) {}

    #[Around]
    public function around(callable $proceed, object $message, MessageContext $context): mixed
    {
        $this->probe->entries[] = 'around';
        return $proceed();
    }
}
