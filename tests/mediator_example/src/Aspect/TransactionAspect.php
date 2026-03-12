<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\Mediator\Attribute\Around;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\TransactionManager;

final class TransactionAspect
{
    public function __construct(private readonly TransactionManager $tx)
    {
    }

    #[Around]
    public function wrap(callable $proceed, object $message, array $context): mixed
    {
        $this->tx->begin();
        try {
            $result = $proceed($message, $context);
            $this->tx->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->tx->rollback();
            throw $e;
        }
    }
}
