<?php

declare(strict_types=1);

use Ultra\Testing\Concurrent\Internal\WorkerRuntime;

/**
 * Announce a named checkpoint and block until the master releases it.
 *
 * Returns the inject value sent by the master (null if not provided).
 * Only meaningful inside a worker closure — calls WorkerRuntime which reads STDIN/STDOUT.
 */
function checkpoint(string $name, mixed $payload = null): mixed
{
    return WorkerRuntime::checkpoint($name, $payload);
}
