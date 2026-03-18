<?php

declare(strict_types=1);

namespace Ultra\EventBus;

interface EventRegistryInterface
{
    public function getClass(string $eventName): ?string;

    public function getName(string $eventClass): ?string;

    /**
     * @return array<string, class-string>
     */
    public function all(): array;
}
