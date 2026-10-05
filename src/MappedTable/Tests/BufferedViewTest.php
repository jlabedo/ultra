<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
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

        self::assertSame(['status' => 'pending'], $rows->row('first', ['status']));
        self::assertSame('first', $rows->row('first')['id']);
        self::assertSame('draft', $rows->row('first')['note']);
        self::assertFalse($rows->row('missing'));
        $all = $rows->rows(['status', 'note']);
        self::assertSame(['first', 'second'], array_map(static fn ($row) => $row->key->value('id'), $all));
        self::assertSame([['status' => 'pending', 'note' => 'draft'], ['status' => 'active', 'note' => 'published']], array_column($all, 'columns'));
        self::assertSame('draft', $rows->rows()[0]->columns['note']);
        self::assertArrayNotHasKey('id', $rows->rows()[0]->columns);
        self::assertFalse($rows->hasPendingChanges());
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

        self::assertSame(['status' => 'active', 'note' => 'draft'], $rows->row('keep', ['status', 'note']));
        self::assertSame(['status' => 'new', 'version_a' => null], $rows->row('added', ['status', 'version_a']));
        self::assertFalse($rows->row('remove'));
        $all = $rows->rows(['status', 'note']);
        self::assertSame(['added', 'keep'], array_map(static fn ($row) => $row->key->value('id'), $all));
        self::assertSame([['status' => 'new', 'note' => 'fresh'], ['status' => 'active', 'note' => 'draft']], array_column($all, 'columns'));
        self::assertSame(['status' => 'pending'], $fixture->stored('keep', ['status']));
        self::assertNotFalse($fixture->stored('remove'));
        self::assertFalse($fixture->stored('added'));
    }

    public function test_check_only_expectations_do_not_change_the_read_view(): void
    {
        $fixture = $this->fixture();
        $fixture->seed(['id' => 'present', 'status' => 'pending']);
        $rows = $fixture->rows;
        $rows->withKey('present')->expectColumnValue('status', 'pending');
        $rows->withKey('missing')->expectAbsent();

        self::assertSame(['status' => 'pending'], $rows->row('present', ['status']));
        self::assertFalse($rows->row('missing'));
        self::assertSame(['present'], array_map(static fn ($row) => $row->key->value('id'), $rows->rows()));
        $rows->expectSameRowKeys(['present']);
    }
}
