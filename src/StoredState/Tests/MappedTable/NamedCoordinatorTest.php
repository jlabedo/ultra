<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\MappedTable\Attribute\DefineMappedRowSet;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetOwner;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\MappedTable\Symfony\MappedTableExtension;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class NamedCoordinatorTest extends TestCase
{
    public function test_named_coordinators_isolate_buffers_for_the_same_mapping(): void
    {
        $builder = $this->builder();
        new MappedTableExtension(rowSets: [
            'second.rows' => NamedRowSets::records(),
        ], unitOfWork: 'second.persistence')->build($builder);
        foreach (['first.rows', 'first.persistence', 'second.rows', 'second.persistence'] as $id) {
            $builder->setAlias('test.' . $id, $id)->setPublic(true);
        }
        $builder->compile();
        $database = new InMemoryRowDatabase();
        $builder->set(MappedTableAdapter::class, new InMemoryMappedTableAdapter($database));

        $first = $builder->get('test.first.rows');
        $second = $builder->get('test.second.rows');
        $first->withKey(1)->insert()->setColumn('name', 'first');
        self::assertNull($second->withKey(1)->get());
        $second->withKey(2)->insert()->setColumn('name', 'second');
        $database->transactional($builder->get('test.first.persistence')->flush(...));
        self::assertSame('first', $second->withKey(1)->get()['name']);
        self::assertNull($first->withKey(2)->get());
        $builder->get('test.second.persistence')->clear();
        self::assertNull($second->withKey(2)->get());
    }

    public function test_one_buffer_cannot_be_enrolled_in_two_coordinators(): void
    {
        $adapter = new InMemoryMappedTableAdapter(new InMemoryRowDatabase());
        $rows = $adapter->compileRowSet(NamedRowSets::records())();
        new MappedTableUnitOfWork(['records' => $rows], $adapter);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already has a flush coordinator');
        new MappedTableUnitOfWork(['records' => $rows], $adapter);
    }

    public function test_owner_and_child_require_the_same_coordinator(): void
    {
        $builder = $this->builder();
        new MappedTableExtension(rowSets: [
            'children' => new MappedRowSetDefinition(
                'children',
                ['parent', 'item'],
                ['parent', 'item'],
                owner: new MappedRowSetOwner('first.rows', ['parent' => 'id']),
            ),
        ])->build($builder);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must use the same coordinator');
        $builder->compile();
    }

    public function test_guard_dependencies_require_the_same_coordinator(): void
    {
        $builder = $this->builder();
        new MappedTableExtension(queryGuards: [new QueryGuardDefinition('records', [
            new QueryGuardDependency('first.rows', 'id'),
            new QueryGuardDependency('second.rows', 'id'),
        ])], rowSets: ['second.rows' => NamedRowSets::records()])->build($builder);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must watch row sets in the same coordinator');
        $builder->compile();
    }

    private function builder(): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        new MappedTableExtension()->build($builder);
        $builder->register(NamedRowSets::class)->setAutoconfigured(true);
        $builder
            ->register(MappedTableAdapter::class, InMemoryMappedTableAdapter::class)
            ->setSynthetic(true)
            ->setPublic(true);

        return $builder;
    }
}

final class NamedRowSets
{
    #[DefineMappedRowSet('first.rows', unitOfWork: 'first.persistence')]
    public static function records(): MappedRowSetDefinition
    {
        return new MappedRowSetDefinition('records', ['id', 'name'], ['id']);
    }
}
