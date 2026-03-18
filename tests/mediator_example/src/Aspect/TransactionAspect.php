<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\TransactionManager;

final class TransactionAspect
{
    public function __construct(private readonly TransactionManager $tx)
    {
    }

    #[Around(static function (\ReflectionMethod $method): ?MatchResult {
        return [] !== $method->getAttributes(Handles::class)
            ? MatchResult::apply()
            : null;
    })]
    public function wrap(callable $proceed, object $message, MessageContext $context): mixed
    {
        $this->tx->begin();
        try {
            $result = $proceed();
            $this->tx->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->tx->rollback();
            throw $e;
        }
    }
}
