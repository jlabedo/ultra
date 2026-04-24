<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Support;

use Ultra\DecisionEngine\Engine;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\Testing\UltraLightContainer;

final readonly class DecisionEngineTestContainer extends UltraLightContainer
{
    public function engine(): Engine
    {
        return $this->get(Engine::class);
    }

    public function mediator(): MediatorInterface
    {
        return $this->get('test.mediator');
    }

    public function eventStore(): InMemoryEventStore
    {
        return $this->get(InMemoryEventStore::class);
    }

    public function eventRegistry(): EventRegistryInterface
    {
        return $this->get(EventRegistryInterface::class);
    }

    public function normalizer(): UltraNormalizer
    {
        return $this->get(UltraNormalizer::class);
    }
}
