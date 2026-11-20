<?php

declare(strict_types=1);

namespace Ultra\Coordination\Refresh;

final readonly class RefreshStep
{
    /**
     * @param \Closure(): void $write Writes the result and any progress in the publication transaction.
     * @param int $acknowledge Count to subtract from the observed marker. Zero leaves the marker pending.
     */
    public function __construct(
        public \Closure $write,
        public int $acknowledge,
    ) {}
}
