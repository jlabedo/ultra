<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests\Fixtures;

use Ultra\MessageHandling\Attribute\SelfHandler;

final readonly class GreetingMessage
{
    public function __construct(public string $name) {}
}

final readonly class FarewellMessage
{
    public function __construct(public string $name) {}
}

final readonly class PingMessage {}

final readonly class AuditedMessage
{
    public function __construct(public string $payload) {}
}

final readonly class WrappedMessage
{
    public function __construct(public string $payload) {}
}

final readonly class NestedParentMessage
{
    public function __construct(public string $payload) {}
}

final readonly class NestedChildMessage
{
    public function __construct(public string $payload) {}
}

final readonly class EventPublishingMessage
{
    public function __construct(public string $payload) {}
}

final readonly class RecordedEvent
{
    public function __construct(public string $payload) {}
}

final readonly class ProcessEntityMessage
{
    public function __construct(public string $entityId) {}
}

final readonly class ProcessPairMessage
{
    public function __construct(
        public string $leftId,
        public string $rightId,
    ) {}
}

final readonly class ObservedMessage
{
    public function __construct(public string $payload) {}
}

final readonly class FailingMessage {}

final readonly class OrderedMessage
{
    public function __construct(public string $payload) {}
}

final readonly class SelfGreetingMessage
{
    public function __construct(public string $name) {}

    #[SelfHandler]
    public function greet(): string
    {
        return 'Hello, ' . $this->name . '!';
    }
}
