<?php

declare(strict_types=1);

namespace Ultra\Coordination\Lease;

/**
 * Signals that pending work exists and a coordinator should wake.
 *
 * Kept deliberately minimal: it only announces "there is work." How a worker is
 * actually woken (enqueue a message, ping a loop, no-op in tests) is the
 * implementation's concern. Subsystems that need to target a specific worker
 * extend this with their own topology-aware methods.
 */
interface Activator
{
    public function signalWorkAvailable(): void;
}
