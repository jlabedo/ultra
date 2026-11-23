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
        $participants = $runtime->persistenceParticipants();
        try {
            $operation();
            foreach ($participants as $participant) {
                $participant->flush();
            }
        } finally {
            foreach ($participants as $participant) {
                $participant->clear();
            }
        }
    }
}
