<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Event;
use Ultra\Mapper\UltraNormalizer;
use Ultra\UnitOfWork\UnitOfWork;

final readonly class ProjectionRuntime
{
    private ProjectionDefinition $definition;

    /** @param list<UnitOfWork> $unitOfWork */
    public function __construct(
        private object $projection,
        private UltraNormalizer $normalizer,
        ?ProjectionDefinition $definition = null,
        private array $unitOfWork = [],
    ) {
        $this->definition = $definition ?? ProjectionDefinition::fromProjection($projection);
    }

    /** @return list<UnitOfWork> */
    public function persistenceParticipants(): array
    {
        $participants = $this->unitOfWork;
        if ($this->projection instanceof UnitOfWork) {
            $participants[] = $this->projection;
        }

        $unique = [];
        foreach ($participants as $participant) {
            $unique[spl_object_id($participant)] = $participant;
        }

        return array_values($unique);
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
        if (null === $this->definition->initializerMethod) {
            return;
        }

        // @mago-expect analysis:string-member-selector
        $this->projection->{$this->definition->initializerMethod}();
    }

    public function reset(): void
    {
        if (null === $this->definition->resetterMethod) {
            return;
        }

        // @mago-expect analysis:string-member-selector
        $this->projection->{$this->definition->resetterMethod}();
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
        // @mago-expect analysis:string-member-selector
        $this->projection->{$method}($deserialized);
    }
}
