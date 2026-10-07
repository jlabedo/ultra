<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests\Support;

use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;

final readonly class OrderedHandler
{
    public function __construct(
        private AdviceOrderingLog $log,
        private bool $fail = false,
    ) {}

    #[Handles]
    public function handle(GreetingMessage $message): string
    {
        $this->log->entries[] = 'handler:' . $message->name;
        if ($this->fail)
            throw new \RuntimeException('boom');
        return $message->name;
    }
}
