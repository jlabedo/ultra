<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionInitializer;

final class ProjectionPlan
{
    private readonly string $projectionId;
    private readonly string $partitionDimension;
    private readonly ProjectionHandlerMap $handlerMap;
    private readonly ?string $initializerMethod;

    public function __construct(
        private readonly object $projection,
        private readonly UltraNormalizer $normalizer,
    ) {
        $reflection = new \ReflectionClass($projection);
        $attributes = $reflection->getAttributes(Projection::class);

        if ([] === $attributes) {
            throw new \InvalidArgumentException(sprintf(
                'Class %s must be annotated with #[Projection].',
                $reflection->getName(),
            ));
        }

        /** @var Projection $attribute */
        $attribute = $attributes[0]->newInstance();
        $this->projectionId = $attribute->name;
        $this->partitionDimension = $attribute->partitionBy;
        $this->handlerMap = ProjectionHandlerMap::fromClass($reflection->getName());
        $this->initializerMethod = $this->resolveInitializerMethod($reflection);
    }

    public function projectionId(): string
    {
        return $this->projectionId;
    }

    public function partitionDimension(): string
    {
        return $this->partitionDimension;
    }

    /**
     * Global query — matches all events of the handled types across all streams.
     * Used when running without segment filtering (step 1) or when the EventStore
     * does not yet support bucket-range queries.
     */
    public function streamQuery(): StreamQuery
    {
        return StreamQuery::all()->ofTypes(...$this->handlerMap->eventTypeNames());
    }

    /**
     * Segment-scoped query — restricts to events whose domain identifier value
     * in the partition dimension falls within [bucketFrom, bucketTo].
     * Requires the bucket column and StreamQuery::forSegment() (step 3).
     */
    public function streamQueryForSegment(int $bucketFrom, int $bucketTo): StreamQuery
    {
        return StreamQuery::forSegment(
            $this->partitionDimension,
            $bucketFrom,
            $bucketTo,
            ...$this->handlerMap->eventTypeNames(),
        );
    }

    public function initialize(): void
    {
        if (null !== $this->initializerMethod) {
            ($this->projection)->{$this->initializerMethod}();
        }
    }

    public function apply(Event $event): void
    {
        $this->handlerMap->apply($this->projection, $event, $this->normalizer);
    }

    private function resolveInitializerMethod(\ReflectionClass $reflection): ?string
    {
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ([] !== $method->getAttributes(ProjectionInitializer::class)) {
                if (0 !== count($method->getParameters())) {
                    throw new \LogicException(sprintf(
                        '#[ProjectionInitializer] method %s::%s must have no parameters.',
                        $reflection->getName(),
                        $method->getName(),
                    ));
                }
                return $method->getName();
            }
        }

        return null;
    }
}
