<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests\Support;

use Ultra\EventSourcedState\EventSourcedStateStore;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\Testing\UltraLightContainer;

final readonly class StatefulMessageHandlingTestContainer extends UltraLightContainer
{
    public function eventSourcedStateStore(): EventSourcedStateStore
    {
        return $this->get(EventSourcedStateStore::class);
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
