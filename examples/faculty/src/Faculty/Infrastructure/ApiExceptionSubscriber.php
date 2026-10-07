<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Violation;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof InvariantViolation) {
            $violations = array_map(static fn(Violation $violation): array => [
                'code' => $violation->code,
                'message' => $violation->message,
            ], $throwable->violations);
            $statusCode = 1 === count($throwable->violations)
            && str_ends_with($throwable->violations[0]->code, '.not_found')
                ? JsonResponse::HTTP_NOT_FOUND
                : JsonResponse::HTTP_UNPROCESSABLE_ENTITY;

            $event->setResponse(new JsonResponse([
                'error' => $throwable->getMessage(),
                'violations' => $violations,
            ], $statusCode));

            return;
        }

        if ($throwable instanceof \InvalidArgumentException) {
            $event->setResponse(new JsonResponse([
                'error' => $throwable->getMessage(),
            ], JsonResponse::HTTP_CONFLICT));
        }
    }
}
