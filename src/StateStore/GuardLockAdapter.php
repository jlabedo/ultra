<?php

declare(strict_types=1);

namespace Ultra\StateStore;

/** Storage boundary for cooperative guards, acquired in the caller's flush transaction. */
interface GuardLockAdapter
{
    public function acquire(string $namespace, string $key, GuardLockMode $mode): void;
}
