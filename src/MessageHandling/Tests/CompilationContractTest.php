<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_Compiler')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class CompilationContractTest extends MessageHandlingCompilerTestCase
{
    public function test_it_requires_around_advices_to_return_proceed_result_or_mixed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Around advice "class@anonymous.*::execute" must declare return type "'.preg_quote(ProceedResult::class, '/').'"\./');

        $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return $message->name;
                }
            })
            ->withService(new class {
                #[Around]
                public function execute(callable $proceed, object $message, MessageContext $context): string
                {
                    return $proceed()->value;
                }
            })
            ->boot()
        ;
    }

    public function test_it_requires_non_around_context_mutating_advices_to_return_patch_arrays(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Before advice "class@anonymous.*::log" must declare no return type, "void", or return type "array" \(optionally nullable\)\./');

        $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return $message->name;
                }
            })
            ->withService(new class {
                #[Before]
                public function log(object $message, MessageContext $context): string
                {
                    return 'invalid';
                }
            })
            ->boot()
        ;
    }

    public function test_it_builds_independent_router_implementations_in_the_same_build_directory(): void
    {
        $firstMediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'First hello, '.$message->name.'!';
                }
            })
            ->boot()->mediator()
        ;

        $secondMediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'Second hello, '.$message->name.'!';
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('First hello, Alice!', $firstMediator->send(new GreetingMessage('Alice')));
        self::assertSame('Second hello, Bob!', $secondMediator->send(new GreetingMessage('Bob')));
    }

    public function test_missing_async_sender_channel_fails_during_compilation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No async message sender is registered for channel "missing".');

        $this->ultraLight()
            ->withService(new class {
                #[Handles]
                #[Asynchronous('missing')]
                public function handle(GreetingMessage $message): void {}
            })
            ->boot()
        ;
    }
}
