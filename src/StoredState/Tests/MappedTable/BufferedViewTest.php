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
    public function test_increment_view_respects_assignment_order_and_creation(string $backend): void
    {
        $fixture = $this->stock($backend);
        $fixture->seed(['id' => 'row', 'units' => 20, 'event_count' => 8]);
        $row = $fixture->rows->withKey('row')->incrementColumn('units', -4)->incrementColumn('event_count', 1);
        static::assertSame(['units' => 16, 'event_count' => 9], $row->get(['units', 'event_count']));

        $row->setColumns(['units' => 10, 'event_count' => 2])->incrementColumn('event_count', -1);
        static::assertSame(['units' => 10, 'event_count' => 1], $row->observe(['units', 'event_count']));
        $new = $fixture
            ->rows
            ->withKey('new')
            ->upsert()
            ->incrementColumn('units', -1)
            ->incrementColumn('event_count', 1);
        static::assertSame(['units' => -1, 'event_count' => 1], $new->get(['units', 'event_count']));
        static::assertFalse($fixture->stored('new', ['units']));
        $fixture->seed(['id' => 'row', 'units' => 25, 'event_count' => 9]);
        $fixture->seed(['id' => 'new', 'units' => 7, 'event_count' => 4]);

        $fixture->flush();

        static::assertSame(['units' => 10, 'event_count' => 1], $fixture->stored('row', ['units', 'event_count']));
        static::assertSame(['units' => 6, 'event_count' => 5], $fixture->stored('new', ['units', 'event_count']));
    }

    #[DataProvider('backends')]
    public function test_reads_project_committed_values_without_registering_work(string $backend): void
    {
        [$fixture, $rows] = $this->ownedRows($backend);
        $owner = ['tenant' => 'one', 'owner' => 'order'];
        $firstKey = new MappedKey([...$owner, 'id' => 'first']);
        $fixture->seed([...$owner, 'id' => 'second', 'status' => 'active', 'note' => 'published']);
        $fixture->seed([...$firstKey->values, 'status' => 'pending', 'note' => 'draft']);

        $first = $rows->withKey($firstKey);
        static::assertSame(['status' => 'pending'], $first->get(['status']));
        static::assertSame($firstKey->values, $first->get([]));
        static::assertSame('first', $first->get()['id']);
        static::assertSame('draft', $first->get()['note']);
        static::assertNull($rows->withKey([...$owner, 'id' => 'missing'])->get([]));
        $all = $rows->rowsForOwner($owner, ['status', 'note']);
        static::assertSame(['first', 'second'], array_map(static fn($row) => $row->key->value('id'), $all));
        static::assertSame(
            [['status' => 'pending', 'note' => 'draft'], ['status' => 'active', 'note' => 'published']],
            array_column($all, 'columns'),
        );
        static::assertEquals($all, $rows->rowsForOwner($owner));
        static::assertEquals($all, $rows->rowsForOwner($owner, null));
        static::assertEquals(
            [new MappedRow($firstKey, []), new MappedRow([...$owner, 'id' => 'second'], [])],
            $rows->rowsForOwner($owner, []),
        );
        static::assertFalse($rows->hasPendingChanges());
    }

    #[DataProvider('backends')]
    public function test_reads_overlay_updates_creations_and_deletions_without_writing_storage(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'keep', 'status' => 'pending', 'note' => 'draft']);
        $fixture->seed(['id' => 'remove', 'status' => 'old']);
        $rows = $fixture->rows;
        $rows->withKey('keep')->setColumn('status', 'active');
        $rows->withKey('remove')->delete();
        $rows->withKey('added')->insert()->setColumns(['status' => 'new', 'note' => 'fresh']);

        static::assertSame(['status' => 'active', 'note' => 'draft'], $rows->withKey('keep')->get(['status', 'note']));
        static::assertSame(['status' => 'new'], $rows->withKey('added')->get(['status', 'version_a']));
        static::assertNull($rows->withKey('remove')->get([]));
        static::assertSame(['id' => 'added'], $rows->withKey('added')->get([]));
        static::assertSame('added', $rows->withKey('added')->get()['id']);
        static::assertSame(['status' => 'new', 'note' => 'fresh'], $rows->withKey('added')->get(['status', 'note']));
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

        static::assertSame(['status' => 'pending'], $rows->withKey('present')->get(['status']));
        static::assertNull($rows->withKey('missing')->get());
        static::assertSame(['id' => 'present'], $rows->withKey('present')->get([]));
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
        static::assertSame(['status' => 'direct'], $rows->rowsForOwner($one->values, ['status'])[0]->columns);
        $all = $rows->rowsForOwner($one);
        static::assertSame(['status' => 'direct', 'note' => 'kept'], $all[0]->columns);
        static::assertEquals($all, $rows->rowsForOwner($one, null));
        static::assertEquals([new MappedRow($oneLine, [])], $rows->rowsForOwner($one, []));

        $newLine = $one->mergedWith(new MappedKey(['id' => 'new']));
        $rows->reconcileForOwner($one->values, [new MappedRow($newLine->values, ['status' => 'from collection'])]);
        $rows->withKey($newLine)->setColumn('note', 'direct again');
        static::assertNull($rows->withKey($oneLine)->get());
        static::assertSame(
            ['status' => 'from collection', 'note' => 'direct again'],
            $rows->withKey($newLine)->get(['status', 'note']),
        );
        static::assertEquals([new MappedRow($newLine, [])], $rows->rowsForOwner($one, []));
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
