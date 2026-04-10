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
    ) {}

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
}
