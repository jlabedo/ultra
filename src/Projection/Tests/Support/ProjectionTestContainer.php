<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Ultra\EventStore\EventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Signal\SignalHandler;
use Ultra\Signal\SignalPoller;
use Ultra\Testing\UltraLightContainer;

final readonly class ProjectionTestContainer extends UltraLightContainer
{
    public function eventStore(): EventStore
    {
        return $this->get('test.event_store');
    }

    public function normalizer(): UltraNormalizer
    {
        return $this->get('test.normalizer');
    }

    public function projectionCatalog(): ProjectionCatalog
    {
        return $this->get('test.projection_catalog');
    }

    public function projectionRunner(): ProjectionRunner
    {
        return $this->get('test.projection_runner');
    }

    public function projectionSegments(): ProjectionSegmentRepositoryInterface
    {
        return $this->get('test.projection_segments');
    }

    /**
     * @return list<SignalHandler>
     */
    public function projectionSignalHandlers(): array
    {
        return $this->get('test.projection_signal_handlers')->all();
    }

    public function signalPoller(): SignalPoller
    {
        return $this->get('test.signal_poller');
    }
}
