<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Exception\NoHandlerFound;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\EmptyMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\FarewellMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class MediatorDispatchTest extends MessageHandlingCompilerTestCase
{
    public function test_it_dispatches_registered_messages_and_returns_handler_results(): void
    {
        $mediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function greet(GreetingMessage $message): string
                {
                    return 'Hello, '.$message->name.'!';
                }

                #[Handles]
                public function farewell(FarewellMessage $message): string
                {
                    return 'Goodbye, '.$message->name.'!';
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('Hello, Alice!', $mediator->send(new GreetingMessage('Alice')));
        self::assertSame('Goodbye, Bob!', $mediator->send(new FarewellMessage('Bob')));
    }

    public function test_it_throws_when_no_handler_is_registered_for_the_message(): void
    {
        $mediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function greet(GreetingMessage $message): string
                {
                    return 'Hello, '.$message->name.'!';
                }
            })
            ->boot()->mediator()
        ;

        $this->expectException(NoHandlerFound::class);

        $mediator->send(new EmptyMessage());
    }
}
