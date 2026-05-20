<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\UnitOfWork\CompositeUnitOfWork;
use Ultra\UnitOfWork\UnitOfWork;

final class RecordingUnitOfWork implements UnitOfWork
{
    /**
     * @param list<string> $calls
     */
    public function __construct(
        private string $name,
        private array &$calls,
        private bool $hasPendingChanges = true,
    ) {}

    public function hasPendingChanges(): bool
    {
        return $this->hasPendingChanges;
    }

    public function flush(): void
    {
        $this->calls[] = $this->name.':flush';
    }

    public function clear(): void
    {
        $this->calls[] = $this->name.':clear';
    }
}

/**
 * @internal
 */
#[CoversClass(CompositeUnitOfWork::class)]
final class CompositeUnitOfWorkTest extends TestCase
{
    public function test_composite_flushes_child_unit_of_works_in_registration_order(): void
    {
        $calls = [];

        $composite = new CompositeUnitOfWork([
            new RecordingUnitOfWork('event_store', $calls),
            new RecordingUnitOfWork('mapped_rows', $calls),
        ]);

        $composite->flush();

        self::assertSame([
            'event_store:flush',
            'mapped_rows:flush',
        ], $calls);
    }

    public function test_composite_clears_child_unit_of_works_in_registration_order(): void
    {
        $calls = [];

        $composite = new CompositeUnitOfWork([
            new RecordingUnitOfWork('event_store', $calls),
            new RecordingUnitOfWork('mapped_rows', $calls),
        ]);

        $composite->clear();

        self::assertSame([
            'event_store:clear',
            'mapped_rows:clear',
        ], $calls);
    }

    public function test_composite_accepts_traversable_unit_of_works(): void
    {
        $calls = [];

        $composite = new CompositeUnitOfWork((static function () use (&$calls): \Generator {
            yield new RecordingUnitOfWork('event_store', $calls);

            yield new RecordingUnitOfWork('mapped_rows', $calls);
        })());

        $composite->flush();

        self::assertSame([
            'event_store:flush',
            'mapped_rows:flush',
        ], $calls);
    }

    public function test_composite_has_pending_changes_when_any_child_has_pending_changes(): void
    {
        $calls = [];

        $composite = new CompositeUnitOfWork([
            new RecordingUnitOfWork('empty', $calls, hasPendingChanges: false),
            new RecordingUnitOfWork('dirty', $calls),
        ]);

        self::assertTrue($composite->hasPendingChanges());
    }

    public function test_composite_has_no_pending_changes_when_all_children_are_empty(): void
    {
        $calls = [];

        $composite = new CompositeUnitOfWork([
            new RecordingUnitOfWork('empty_one', $calls, hasPendingChanges: false),
            new RecordingUnitOfWork('empty_two', $calls, hasPendingChanges: false),
        ]);

        self::assertFalse($composite->hasPendingChanges());
    }
}
