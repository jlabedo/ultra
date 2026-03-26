<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\EventTopologyProvider;

final readonly class ProjectionPlanValidator
{
    public function __construct(
        private EventTopologyProvider $topologyProvider,
    ) {}

    public function validate(ProjectionPlan $plan): void
    {
        if (! $plan->isPartitioned()) {
            return;
        }

        $dimensions = $plan->partitionStrategy()?->dimensions() ?? [];
        if ([] === $dimensions) {
            return;
        }

        foreach ($plan->eventTypes as $eventType) {
            $topology = $this->topologyProvider->get($eventType);
            if (null === $topology) {
                throw new \LogicException(sprintf(
                    'Projection "%s" references event type "%s" but no event topology is registered for it.',
                    $plan->name(),
                    $eventType,
                ));
            }

            if (! $topology->supportsAll($dimensions)) {
                throw new \LogicException(sprintf(
                    'Projection "%s" cannot partition event type "%s" by [%s].',
                    $plan->name(),
                    $eventType,
                    implode(', ', $dimensions),
                ));
            }
        }
    }
}
