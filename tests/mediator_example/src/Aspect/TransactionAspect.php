<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Aspect;

use Ultra\Mediator_Example\OrderProcessing\Infrastructure\TransactionManager;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;

final class TransactionAspect
{
    public function __construct(private readonly TransactionManager $tx)
    {
    }

    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        $method = $ctx->method();
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
