<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\Testing\Concurrent\Cluster;

/** @internal */
abstract class ConcurrentTestCase extends TestCase
{
    protected Cluster $cluster;

    protected function setUp(): void
    {
        $this->cluster = new Cluster(timeout: 3.0);
    }

    protected function tearDown(): void
    {
        unset($this->cluster);
    }
}
