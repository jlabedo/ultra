<?php

declare(strict_types=1);

namespace Ultra\Projection;

interface ProjectionTransactionManagerInterface
{
    public function transactional(callable $operation): mixed;
}
