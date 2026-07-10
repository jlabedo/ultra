<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryMappedOwnedRowSetAdapter;
use Ultra\MappedTable\InMemoryMappedRowSetAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedOwnedRowSet;
use Ultra\MappedTable\MappedOwnedRowSetAdapter;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\OwnedRowSetUpdatePlan;
use Ultra\MappedTable\OwnedSetParentMatchDelete;
use Ultra\MappedTable\OwnedSetReplace;
use Ultra\MappedTable\PendingOwnedSetReplace;
use Ultra\UnitOfWork\CompositeUnitOfWork;

/**
 * @internal
 */
#[CoversClass(MappedOwnedRowSet::class)]
#[CoversClass(PendingOwnedSetReplace::class)]
#[CoversClass(OwnedRowSetUpdatePlan::class)]
#[CoversClass(OwnedSetReplace::class)]
#[CoversClass(InMemoryMappedOwnedRowSetAdapter::class)]
final class MappedOwnedRowSetTest extends TestCase
{
    private const TABLE = 'eligibility_intervals';

    public function test_replace_from_empty_writes_rows(): void
    {
        [$database, $set] = $this->newSet();

        $set->withParent(new MappedKey(['subject_id' => 's-1', 'capability_id' => 'c-1']))
            ->replace([
                ['eligible_from' => '2026-01-01', 'eligible_to' => '2026-06-01'],
                ['eligible_from' => '2026-07-01', 'eligible_to' => null],
            ]);

        $set->flush();

        self::assertSame([
            ['capability_id' => 'c-1', 'subject_id' => 's-1', 'eligible_from' => '2026-01-01', 'eligible_to' => '2026-06-01'],
            ['capability_id' => 'c-1', 'subject_id' => 's-1', 'eligible_from' => '2026-07-01', 'eligible_to' => null],
        ], $database->rowsForParent(self::TABLE, ['subject_id' => 's-1', 'capability_id' => 'c-1']));
    }

    public function test_replace_overwrites_prior_set(): void
    {
        [$database, $set] = $this->newSet();

        $parent = ['subject_id' => 's-1', 'capability_id' => 'c-1'];

        $set->withParent(new MappedKey($parent))->replace([
            ['eligible_from' => '2026-01-01', 'eligible_to' => '2026-02-01'],
            ['eligible_from' => '2026-03-01', 'eligible_to' => '2026-04-01'],
        ]);
        $set->flush();

        $set->withParent(new MappedKey($parent))->replace([
            ['eligible_from' => '2026-05-01', 'eligible_to' => null],
        ]);
        $set->flush();

        $rows = $database->rowsForParent(self::TABLE, $parent);
        self::assertCount(1, $rows);
        self::assertSame('2026-05-01', $rows[0]['eligible_from']);
        self::assertNull($rows[0]['eligible_to']);
    }

    public function test_replace_with_empty_clears_owned_set(): void
    {
        [$database, $set] = $this->newSet();
        $parent = ['subject_id' => 's-1', 'capability_id' => 'c-1'];

        $set->withParent(new MappedKey($parent))->replace([
            ['eligible_from' => '2026-01-01', 'eligible_to' => null],
        ]);
        $set->flush();

        $set->withParent(new MappedKey($parent))->replace([]);
        $set->flush();

        self::assertSame([], $database->rowsForParent(self::TABLE, $parent));
    }

    public function test_parent_isolation_replacing_one_pair_leaves_others_alone(): void
    {
        [$database, $set] = $this->newSet();

        $parentA = ['subject_id' => 's-1', 'capability_id' => 'c-1'];
        $parentB = ['subject_id' => 's-2', 'capability_id' => 'c-1'];
        $parentC = ['subject_id' => 's-1', 'capability_id' => 'c-2'];

        $set->withParent(new MappedKey($parentA))->replace([['eligible_from' => '2026-01-01', 'eligible_to' => null]]);
        $set->withParent(new MappedKey($parentB))->replace([['eligible_from' => '2026-02-01', 'eligible_to' => null]]);
        $set->withParent(new MappedKey($parentC))->replace([['eligible_from' => '2026-03-01', 'eligible_to' => null]]);
        $set->flush();

        $set->withParent(new MappedKey($parentA))->replace([['eligible_from' => '2026-12-12', 'eligible_to' => null]]);
        $set->flush();

        self::assertSame('2026-12-12', $database->rowsForParent(self::TABLE, $parentA)[0]['eligible_from']);
        self::assertSame('2026-02-01', $database->rowsForParent(self::TABLE, $parentB)[0]['eligible_from']);
        self::assertSame('2026-03-01', $database->rowsForParent(self::TABLE, $parentC)[0]['eligible_from']);
    }

    public function test_rows_for_parent_reflects_buffered_replacement(): void
    {
        [, $set] = $this->newSet();
        $parent = ['subject_id' => 's-1', 'capability_id' => 'c-1'];

        $set->withParent(new MappedKey($parent))->replace([
            ['eligible_from' => '2026-01-01', 'eligible_to' => null],
        ]);

        self::assertCount(1, $set->rowsForParent(new MappedKey($parent)));
    }

    public function test_has_pending_changes(): void
    {
        [, $set] = $this->newSet();
        self::assertFalse($set->hasPendingChanges());

        $set->withParent(new MappedKey(['subject_id' => 's', 'capability_id' => 'c']))->replace([]);
        self::assertTrue($set->hasPendingChanges());

        $set->clear();
        self::assertFalse($set->hasPendingChanges());
    }

    public function test_flush_passes_parent_sorted_plan_to_adapter(): void
    {
        $adapter = new RecordingOwnedAdapter();
        $set = new MappedOwnedRowSet($adapter);

        $set->withParent(new MappedKey(['subject_id' => 's-2', 'capability_id' => 'c-1']))->replace([]);
        $set->withParent(new MappedKey(['subject_id' => 's-1', 'capability_id' => 'c-1']))->replace([]);
        $set->flush();

        self::assertCount(1, $adapter->plans);
        $replacements = $adapter->plans[0]->replacements;
        self::assertCount(2, $replacements);
        self::assertSame('s-1', $replacements[0]->parentKey->value('subject_id'));
        self::assertSame('s-2', $replacements[1]->parentKey->value('subject_id'));
    }

    public function test_parent_key_columns_are_required(): void
    {
        [, $set] = $this->newSet();

        $this->expectException(\InvalidArgumentException::class);
        $set->withParent(new MappedKey([]));
    }

    public function test_participates_in_composite_unit_of_work_alongside_mapped_row_set(): void
    {
        $database = new InMemoryRowDatabase();
        $set = new MappedOwnedRowSet(new InMemoryMappedOwnedRowSetAdapter($database, self::TABLE));
        $rowSet = new MappedRowSet(new InMemoryMappedRowSetAdapter($database, 'subjects', ['id']));

        $composite = new CompositeUnitOfWork([$rowSet, $set]);

        $rowSet->withKey('s-1')->insert()->setColumn('status', 'active');
        $set->withParent(new MappedKey(['subject_id' => 's-1', 'capability_id' => 'c-1']))
            ->replace([['eligible_from' => '2026-01-01', 'eligible_to' => null]]);

        self::assertTrue($composite->hasPendingChanges());

        $database->beginTransaction();
        $composite->flush();
        $database->commit();

        self::assertSame('active', $database->row('subjects', 'id', 's-1')['status']);
        self::assertSame([
            ['capability_id' => 'c-1', 'subject_id' => 's-1', 'eligible_from' => '2026-01-01', 'eligible_to' => null],
        ], $database->rowsForParent(self::TABLE, ['subject_id' => 's-1', 'capability_id' => 'c-1']));

        $composite->clear();
        self::assertFalse($composite->hasPendingChanges());
    }

    public function test_rollback_reverts_owned_set_writes(): void
    {
        $database = new InMemoryRowDatabase();
        $set = new MappedOwnedRowSet(new InMemoryMappedOwnedRowSetAdapter($database, self::TABLE));
        $parent = ['subject_id' => 's-1', 'capability_id' => 'c-1'];

        $set->withParent(new MappedKey($parent))->replace([['eligible_from' => '2026-01-01', 'eligible_to' => null]]);
        $set->flush();
        $set->clear();

        $database->beginTransaction();
        $set->withParent(new MappedKey($parent))->replace([['eligible_from' => '2027-01-01', 'eligible_to' => null]]);
        $set->flush();
        $database->rollBack();

        self::assertSame('2026-01-01', $database->rowsForParent(self::TABLE, $parent)[0]['eligible_from']);
    }

    public function test_delete_parents_matching_removes_rows_by_partial_key(): void
    {
        [$database, $set] = $this->newSet();

        $set->withParent(new MappedKey(['subject_id' => 's-1', 'capability_id' => 'c-1']))
            ->replace([['eligible_from' => '2026-01-01', 'eligible_to' => null]]);
        $set->withParent(new MappedKey(['subject_id' => 's-2', 'capability_id' => 'c-1']))
            ->replace([['eligible_from' => '2026-01-01', 'eligible_to' => null]]);
        $set->withParent(new MappedKey(['subject_id' => 's-1', 'capability_id' => 'c-2']))
            ->replace([['eligible_from' => '2026-01-01', 'eligible_to' => null]]);
        $set->flush();

        $set->deleteParentsMatching(['capability_id' => 'c-1']);
        $set->flush();

        self::assertSame([], $database->rowsForParent(self::TABLE, ['subject_id' => 's-1', 'capability_id' => 'c-1']));
        self::assertSame([], $database->rowsForParent(self::TABLE, ['subject_id' => 's-2', 'capability_id' => 'c-1']));
        self::assertCount(1, $database->rowsForParent(self::TABLE, ['subject_id' => 's-1', 'capability_id' => 'c-2']));
    }

    public function test_delete_parents_matching_drops_pending_replaces_then_subsequent_replace_wins(): void
    {
        [$database, $set] = $this->newSet();

        // pending replace for c-1; then queue a delete that wipes c-1; then a
        // *new* replace for the same parent — the new one must survive.
        $set->withParent(new MappedKey(['subject_id' => 's-1', 'capability_id' => 'c-1']))
            ->replace([['eligible_from' => '2025-01-01', 'eligible_to' => '2025-02-01']]);
        $set->deleteParentsMatching(['capability_id' => 'c-1']);
        $set->withParent(new MappedKey(['subject_id' => 's-1', 'capability_id' => 'c-1']))
            ->replace([['eligible_from' => '2026-01-01', 'eligible_to' => null]]);
        $set->flush();

        $rows = $database->rowsForParent(self::TABLE, ['subject_id' => 's-1', 'capability_id' => 'c-1']);
        self::assertCount(1, $rows);
        self::assertSame('2026-01-01', $rows[0]['eligible_from']);
    }

    public function test_delete_parents_matching_emits_plan_with_deletes_before_replacements(): void
    {
        $adapter = new RecordingOwnedAdapter();
        $set = new MappedOwnedRowSet($adapter);

        $set->withParent(new MappedKey(['subject_id' => 's-1', 'capability_id' => 'c-2']))->replace([]);
        $set->deleteParentsMatching(['capability_id' => 'c-1']);
        $set->flush();

        self::assertCount(1, $adapter->plans);
        $plan = $adapter->plans[0];
        self::assertCount(1, $plan->parentMatchDeletes);
        self::assertSame(['capability_id' => 'c-1'], $plan->parentMatchDeletes[0]->partialParentKey);
        self::assertCount(1, $plan->replacements);
    }

    public function test_delete_parents_matching_rejects_empty_partial_key(): void
    {
        [, $set] = $this->newSet();
        $this->expectException(\InvalidArgumentException::class);
        $set->deleteParentsMatching([]);
    }

    public function test_has_pending_changes_reflects_queued_deletes(): void
    {
        [, $set] = $this->newSet();
        self::assertFalse($set->hasPendingChanges());

        $set->deleteParentsMatching(['capability_id' => 'c-1']);
        self::assertTrue($set->hasPendingChanges());

        $set->clear();
        self::assertFalse($set->hasPendingChanges());
    }

    /**
     * @return array{InMemoryRowDatabase, MappedOwnedRowSet}
     */
    private function newSet(): array
    {
        $database = new InMemoryRowDatabase();
        $adapter = new InMemoryMappedOwnedRowSetAdapter($database, self::TABLE);

        return [$database, new MappedOwnedRowSet($adapter)];
    }
}

final class RecordingOwnedAdapter implements MappedOwnedRowSetAdapter
{
    /** @var list<OwnedRowSetUpdatePlan> */
    public array $plans = [];

    public function rowsForParent(MappedKey $parentKey): array
    {
        return [];
    }

    public function apply(OwnedRowSetUpdatePlan $plan): void
    {
        $this->plans[] = $plan;
    }
}
