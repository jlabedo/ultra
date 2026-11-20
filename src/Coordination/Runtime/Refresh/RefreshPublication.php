<?php

declare(strict_types=1);

namespace Ultra\Coordination\Refresh;

interface RefreshPublication
{
    /** Read the channel revision before observing any work or source state. */
    public function revision(string $channel): int;

    /**
     * Run the write atomically with advancing the channel revision. Return false without
     * calling the write if another publication has advanced the revision.
     *
     * @param \Closure(): void $write
     */
    public function publish(string $channel, int $revision, \Closure $write): bool;
}
