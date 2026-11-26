<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Event;
use Ultra\Mapper\UltraNormalizer;
use Ultra\UnitOfWork\UnitOfWork;

final class ProjectionRuntime
{
    private bool $applying = false;
    private readonly ProjectionDefinition $definition;

    /** @var list<UnitOfWork> */
    private readonly array $participants;

    /** @param list<UnitOfWork> $unitOfWork */
    public function __construct(
        private readonly object $projection,
        private readonly UltraNormalizer $normalizer,
        ?ProjectionDefinition $definition = null,
        array $unitOfWork = [],
    ) {
        $this->definition = $definition ?? ProjectionDefinition::fromProjection($projection);
        if ($projection instanceof UnitOfWork) {
            $unitOfWork[] = $projection;
        }

        $unique = [];
        foreach ($unitOfWork as $participant) {
            $unique[spl_object_id($participant)] = $participant;
        }
        $this->participants = array_values($unique);
    }

    /**
     * @internal Used by the catalog to check exclusive participant ownership.
     * @return list<UnitOfWork>
     */
    public function persistenceParticipants(): array
    {
        return $this->participants;
    }

    /** @param list<Event> $events */
    public function applyBatch(array $events): void
    {
        $this->withPersistence(function () use ($events): void {
            foreach ($events as $event) {
                $this->apply($event);
            }
            $this->flush();
        });
    }

    public function projectionId(): string
    {
        return $this->definition->projectionId;
    }

    public function definition(): ProjectionDefinition
    {
        return $this->definition;
    }

    public function initialize(): void
    {
        $this->withPersistence(function (): void {
            if (null !== $this->definition->initializerMethod) {
                // @mago-expect analysis:string-member-selector
                $this->projection->{$this->definition->initializerMethod}();
            }
        });
    }

    public function reset(): void
    {
        $this->withPersistence(function (): void {
            if (null !== $this->definition->resetterMethod) {
                // @mago-expect analysis:string-member-selector
                $this->projection->{$this->definition->resetterMethod}();
            }
        });
    }

    public function flush(): void
    {
        if (null === $this->definition->flushMethod) {
            return;
        }

        // @mago-expect analysis:string-member-selector
        $this->projection->{$this->definition->flushMethod}();
    }

    public function apply(Event $event): void
    {
        $handler = $this->definition->handlerFor($event);
        if (null === $handler) {
            return;
        }

        $deserialized = $this->normalizer->fromScalar($event->payload, $handler->eventClass);
        $method = $handler->method;
        if ($handler->receivesMetadata) {
            // @mago-expect analysis:string-member-selector
            $this->projection->{$method}($deserialized, $event->metadata);

            return;
        }

        // @mago-expect analysis:string-member-selector
        $this->projection->{$method}($deserialized);
    }

    private function withPersistence(callable $operation): void
    {
        if ($this->applying) {
            throw new \LogicException(sprintf('Projection "%s" already has an active batch.', $this->projectionId()));
        }

        $this->applying = true;
        try {
            try {
                $operation();
                foreach ($this->participants as $participant) {
                    $participant->flush();
                }
            } finally {
                foreach ($this->participants as $participant) {
                    $participant->clear();
                }
            }
        } finally {
            $this->applying = false;
        }
    }
}
