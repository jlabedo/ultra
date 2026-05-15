<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork_Bundle\MessageHandling;

use Ultra\MessageHandling\Attribute\AdvicePhase;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWork;

final readonly class UnitOfWorkAspect
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private TransactionManager $transactionManager,
    ) {}

    #[Around(self::match(...), priority: -100)]
    public function around(callable $proceed, object $message, MessageContext $context, array $config): ProceedResult
    {
        if ($context->has(UnitOfWork::class)) {
            return $proceed();
        }

        if (true !== ($config['createRoot'] ?? false)) {
            return $proceed();
        }

        try {
            $result = $proceed($context->with(UnitOfWork::class, $this->unitOfWork));
            $this->transactionManager->transactional(function (): void {
                $this->unitOfWork->flush();
            });
        } catch (\Throwable $e) {
            $this->unitOfWork->clear();

            throw $e;
        }

        $this->unitOfWork->clear();

        return $result->withContext($result->context->without(UnitOfWork::class));
    }

    public static function match(HandlerMatchContext $context): ?MatchResult
    {
        $isEventHandler = [] !== $context->method()->getAttributes(EventHandler::class);

        return MatchResult::apply(
            config: [
                'createRoot' => !$context->isAsync() && !$isEventHandler,
            ],
            phases: [AdvicePhase::Execute],
        );
    }
}
