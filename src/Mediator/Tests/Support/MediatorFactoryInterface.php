<?php

declare(strict_types=1);

namespace Ultra\Mediator\Tests\Support;

use Ultra\MessageHandling\MediatorInterface;

interface MediatorFactoryInterface
{
    /**
     * @param array<mixed, object> $services
     * @param list<class-string> $classes
     */
    public function createMediator(array $services, array $classes, string $scenario): MediatorInterface;
}
