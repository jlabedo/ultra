<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;

final readonly class TransactionalUnitOfWorkAspect
{
    public function __construct(private TransactionalUnitOfWorkRunner $runner) {}

    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        return MatchResult::apply();
    }, priority: 1000)]
    public function wrap(callable $proceed, object $message, MessageContext $context): ProceedResult
    {
        if ($this->runner->hasActiveTransaction()) {
            try {
                return $proceed();
            } catch (\Throwable $e) {
                $this->runner->markRollbackOnly();

                throw $e;
            }
        }

        return $this->runner->run(static fn (): ProceedResult => $proceed());
    }
}
