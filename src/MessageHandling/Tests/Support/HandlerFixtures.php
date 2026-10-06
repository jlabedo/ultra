<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests\Support;

use Ultra\MessageHandling_Compiler\Tests\Fixtures\ProcessEntityMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\ProcessPairMessage;

final class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello, '.$name.'!';
    }
}

final class Entity
{
    public bool $processed = false;

    public function __construct(public readonly string $id) {}

    public function process(): void
    {
        $this->processed = true;
    }
}

final class EntityRepository
{
    /** @var array<string, Entity> */
    public array $saved = [];

    public function load(ProcessEntityMessage $message): Entity
    {
        return new Entity($message->entityId);
    }

    public function save(Entity $entity): void
    {
        $this->saved[$entity->id] = $entity;
    }
}

final readonly class LeftEntity
{
    public function __construct(public string $id) {}
}

final readonly class RightEntity
{
    public function __construct(public string $id) {}
}

final class LeftRepository
{
    public function load(ProcessPairMessage $message): LeftEntity
    {
        return new LeftEntity($message->leftId);
    }
}

final class RightRepository
{
    public function load(ProcessPairMessage $message): RightEntity
    {
        return new RightEntity($message->rightId);
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class EntityConfig
{
    public function __construct(public string $idProperty) {}
}

#[EntityConfig(idProperty: 'leftId')]
final readonly class ConfiguredLeftEntity
{
    public function __construct(public string $id) {}
}

#[EntityConfig(idProperty: 'rightId')]
final readonly class ConfiguredRightEntity
{
    public function __construct(public string $id) {}
}

final class EntityManager
{
    /** @var list<array{class: class-string, id: string}> */
    public array $findCalls = [];

    /** @var array<class-string, array<string, object>> */
    public array $saved = [];

    /**
     * @param class-string $class
     */
    public function find(string $class, string $id): object
    {
        $this->findCalls[] = ['class' => $class, 'id' => $id];

        return new $class($id);
    }

    public function save(object $entity): void
    {
        $this->saved[$entity::class][$entity->id] = $entity;
    }
}

final class SelfHandlerGreeter
{
    public function greet(string $name): string
    {
        return 'Hello, '.$name.'!';
    }
}
