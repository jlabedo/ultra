<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class BufferedViewTest extends RowSetTestCase
{
    #[DataProvider('backends')]
    public function test_reads_project_committed_values_without_registering_work(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'second', 'status' => 'active', 'note' => 'published']);
        $fixture->seed(['id' => 'first', 'status' => 'pending', 'note' => 'draft']);
        $rows = $fixture->rows;

        static::assertSame(['status' => 'pending'], $rows->row('first', ['status']));
        static::assertSame('first', $rows->row('first')['id']);
        static::assertSame('draft', $rows->row('first')['note']);
        static::assertFalse($rows->row('missing'));
        $all = $rows->rows(['status', 'note']);
        static::assertSame(['first', 'second'], array_map(static fn($row) => $row->key->value('id'), $all));
        static::assertSame(
            [['status' => 'pending', 'note' => 'draft'], ['status' => 'active', 'note' => 'published']],
            array_column($all, 'columns'),
        );
        static::assertSame('draft', $rows->rows()[0]->columns['note']);
        static::assertArrayNotHasKey('id', $rows->rows()[0]->columns);
        static::assertFalse($rows->hasPendingChanges());
    }

    public function test_reads_overlay_updates_creations_and_deletions_without_writing_storage(): void
    {
        $fixture = $this->fixture();
        $fixture->seed(['id' => 'keep', 'status' => 'pending', 'note' => 'draft']);
        $fixture->seed(['id' => 'remove', 'status' => 'old']);
        $rows = $fixture->rows;
        $rows->withKey('keep')->setColumn('status', 'active');
        $rows->withKey('remove')->delete();
        $rows->withKey('added')->insert()->setColumns(['status' => 'new', 'note' => 'fresh']);

        static::assertSame(['status' => 'active', 'note' => 'draft'], $rows->row('keep', ['status', 'note']));
        static::assertSame(['status' => 'new', 'version_a' => null], $rows->row('added', ['status', 'version_a']));
        static::assertFalse($rows->row('remove'));
        $all = $rows->rows(['status', 'note']);
        static::assertSame(['added', 'keep'], array_map(static fn($row) => $row->key->value('id'), $all));
        static::assertSame(
            [['status' => 'new', 'note' => 'fresh'], ['status' => 'active', 'note' => 'draft']],
            array_column($all, 'columns'),
        );
        static::assertSame(['status' => 'pending'], $fixture->stored('keep', ['status']));
        static::assertNotFalse($fixture->stored('remove'));
        static::assertFalse($fixture->stored('added'));
    }

    public function test_check_only_expectations_do_not_change_the_read_view(): void
    {
        $fixture = $this->fixture();
        $fixture->seed(['id' => 'present', 'status' => 'pending']);
        $rows = $fixture->rows;
        $rows->withKey('present')->expectColumnValue('status', 'pending');
        $rows->withKey('missing')->expectAbsent();

        static::assertSame(['status' => 'pending'], $rows->row('present', ['status']));
        static::assertFalse($rows->row('missing'));
        static::assertSame(['present'], array_map(static fn($row) => $row->key->value('id'), $rows->rows()));
        $rows->expectSameRowKeys(['present']);
    }

    #[DataProvider('backends')]
    public function test_owner_reads_and_direct_reads_share_the_pending_changes(string $backend): void
    {
        [$fixture, $rows] = $this->ownedRows($backend);
        $one = new MappedKey(['tenant' => 'one', 'owner' => 'order']);
        $two = new MappedKey(['tenant' => 'two', 'owner' => 'order']);
        $oneLine = $one->mergedWith(new MappedKey(['id' => 'line']));
        $twoLine = $two->mergedWith(new MappedKey(['id' => 'line']));
        $fixture->seed([...$oneLine->values, 'status' => 'old', 'note' => 'kept']);
        $fixture->seed([...$twoLine->values, 'status' => 'other']);

        $rows->withKey($oneLine)->setColumn('status', 'direct');
        static::assertSame(['status' => 'direct'], $rows->rowsForOwner($one, ['status'])[0]->columns);

        $newLine = $one->mergedWith(new MappedKey(['id' => 'new']));
        $rows->reconcileForOwner($one, [new MappedRow($newLine, ['status' => 'from collection'])]);
        $rows->withKey($newLine)->setColumn('note', 'direct again');
        static::assertFalse($rows->row($oneLine));
        static::assertSame(
            ['status' => 'from collection', 'note' => 'direct again'],
            $rows->row($newLine, ['status', 'note']),
        );
        static::assertSame(
            [$twoLine->values, $newLine->values],
            array_map(static fn(MappedRow $row): array => $row->key->values, $rows->rows()),
        );
        static::assertSame(['status' => 'other'], $rows->rowsForOwner($two, ['status'])[0]->columns);
        static::assertSame(['status' => 'old'], $fixture->stored($oneLine, ['status']));
        static::assertFalse($fixture->stored($newLine, ['status']));

        $fixture->flush($rows);
        static::assertFalse($fixture->stored($oneLine, ['status']));
        static::assertSame(
            ['status' => 'from collection', 'note' => 'direct again'],
            $fixture->stored($newLine, ['status', 'note']),
        );
        static::assertFalse($rows->hasPendingChanges());
    }
}
