<?php

declare(strict_types=1);

namespace Ultra\Events;

interface EventRegistryInterface
{
    public function getClass(string $eventName): ?string;

    public function getName(string $eventClass): ?string;

    /**
     * @return array<string, string>
     */
    public function getDomainIdentifiers(object $event): array;

    /**
     * @return array<array-key, class-string>
     */
    public function all(): array;
}
