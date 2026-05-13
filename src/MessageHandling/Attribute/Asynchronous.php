<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Asynchronous
{
    public function __construct(
        public string $channel = 'default',
    ) {}
}
