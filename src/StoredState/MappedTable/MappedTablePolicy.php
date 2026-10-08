<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** Operations supported by every adapter and scoped view of a table. */
final readonly class MappedTablePolicy
{
    public function __construct(
        public bool $allowAbsentObservations = true,
    ) {}

    /** Validate the final plan before the adapter checks storage or applies writes. */
    public function assertSupported(RowSetUpdatePlan $plan, string $tableName): void
    {
        if ($this->allowAbsentObservations) {
            return;
        }

        foreach ($plan->rowUpdates as $update) {
            if (!$update->writes() && RowUpdatePlan::EXPECTED_ABSENT === $update->expectedPresence) {
                throw new \LogicException(sprintf(
                    'Check-only absence observations are disabled for table "%s".',
                    $tableName,
                ));
            }
        }
    }
}
