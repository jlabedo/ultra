<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Event;

final readonly class ProjectionBatchExecutor
{
    /** @param list<Event> $events */
    public function execute(ProjectionRuntime $runtime, array $events): void
    {
        $this->lifecycle($runtime, static function () use ($runtime, $events): void {
            foreach ($events as $event) {
                $runtime->apply($event);
            }
            $runtime->flush();
        });
    }

    /** @param callable(): void $operation */
    public function lifecycle(ProjectionRuntime $runtime, callable $operation): void
    {
        try {
            $operation();
            $this->flushPersistence($runtime);
        } finally {
            $this->clear($runtime);
        }
    }

    private function flushPersistence(ProjectionRuntime $runtime): void
    {
        foreach ($runtime->persistenceParticipants() as $participant) {
            $participant->flush();
        }
    }

    private function clear(ProjectionRuntime $runtime): void
    {
        foreach ($runtime->persistenceParticipants() as $participant) {
            $participant->clear();
        }
    }
}
