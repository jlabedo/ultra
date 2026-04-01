<?php

declare(strict_types=1);

namespace Ultra\Mediator\Tests\Support;

use Ultra\Mediator\MediatorInterface;

interface MediatorFactoryInterface
{
    /**
     * @param list<object> $services
     * @param list<class-string> $classes
     */
    public function createMediator(array $services, array $classes, string $scenario): MediatorInterface;
}
