<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\EmptyMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AsyncQueueLeaseTest extends MessageHandlingCompilerTestCase
{
    public function test_worker_releases_failed_delivery_for_redelivery(): void
    {
        $calls = 0;

        $container = $this->ultraLight()
            ->withService(new class($calls) {
                public int $calls;

                public function __construct(int &$calls)
                {
                    $this->calls = &$calls;
                }

                #[Handles]
                #[Asynchronous]
                public function handle(EmptyMessage $message): void
                {
                    ++$this->calls;

                    if (1 === $this->calls) {
                        throw new \RuntimeException('temporary failure');
                    }
                }
            })
            ->boot()
        ;

        $container->mediator()->send(new EmptyMessage());

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('temporary failure');

        try {
            $container->queueWorker()->processOne();
        } finally {
            self::assertSame(1, $container->asyncQueue()->count());
        }
    }
}
