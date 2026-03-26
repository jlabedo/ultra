<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Exception\StreamNotFoundException;

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
            $event->setResponse(new JsonResponse([
                'error' => $throwable->getMessage(),
                'violations' => array_map(
                    static fn (\Ultra\DecisionEngine\Violation $violation): array => [
                        'code' => $violation->code,
                        'message' => $violation->message,
                    ],
                    $throwable->violations,
                ),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY));

            return;
        }

        if ($throwable instanceof StreamNotFoundException) {
            $event->setResponse(new JsonResponse([
                'error' => $throwable->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND));

            return;
        }

        if ($throwable instanceof \InvalidArgumentException) {
            $event->setResponse(new JsonResponse([
                'error' => $throwable->getMessage(),
            ], JsonResponse::HTTP_CONFLICT));
        }
    }
}
