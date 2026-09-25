<?php

declare(strict_types=1);

namespace App\Tests;

use Ultra\MessageHandling\MediatorInterface;
use Ultra\StateStore\StateStore;
use App\Tests\Driver\BookingDriver;
use App\Tests\Driver\StateStoreDriver;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
final class StateStoreBookingTest extends BookingScenarioTestCase
{
    protected function driver(): BookingDriver
    {
        return new StateStoreDriver(
            self::getContainer()->get(MediatorInterface::class),
            self::getContainer()->get(StateStore::class),
        );
    }

    /**
     * Known gap: when two decisions in one unit of work load the same collection-backed row (the company-month
     * usage), the second expect() registers a different row-key set and MappedRowSet rejects it as a conflict.
     */
    #[Group('finding')]
    public function test_a_weekly_series_is_all_or_nothing(): void
    {
        parent::test_a_weekly_series_is_all_or_nothing();
    }

    #[Group('finding')]
    public function test_a_weekly_series_respects_the_quota_including_its_own_occurrences(): void
    {
        parent::test_a_weekly_series_respects_the_quota_including_its_own_occurrences();
    }
}
