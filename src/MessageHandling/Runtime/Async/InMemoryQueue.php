<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\Time\ClockInterface;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

/** @implements DeadLetterQueue<AsyncEnvelope> */
final class InMemoryQueue implements AsyncMessageSender, AsyncMessageReceiver, DeadLetterQueue
{
    /**
     * @var array<array-key, array{
     *     envelope: AsyncEnvelope,
     *     availableAt: DatePoint,
     *     leaseId: null|string,
     *     leasedUntil: null|DatePoint,
     *     attempts: int
     * }>
     */
    private array $messages = [];

    /** @var array<string, AsyncMessageId> */
    private array $idsByKey = [];

    /** @var array<array-key, DeadLetter<AsyncEnvelope>> */
    private array $deadLetters = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly AsyncRetryPolicy $retryPolicy = new AsyncRetryPolicy(),
    ) {}

    public function enqueue(AsyncEnvelope $envelope, ?AsyncEnqueueOptions $options = null): AsyncMessageId
    {
        $options ??= new AsyncEnqueueOptions();
        $messageId = $options->messageId ?? AsyncMessageId::generate();
        if (isset($this->deadLetters[$messageId->value])) {
            throw new \InvalidArgumentException(sprintf('Async message "%s" is quarantined.', $messageId->value));
        }

        $now = $this->clock->now();
        $availableAt =
            $options->availableAt ?? ($options->delay instanceof Duration ? $now->add($options->delay) : $now);

        if ($options->replace && null !== $options->key) {
            $existingId = $this->idsByKey[$options->key] ?? null;
            if (null !== $existingId) {
                unset($this->messages[$existingId->value], $this->idsByKey[$options->key]);
            }
        }

        $this->storeMessage($messageId, $envelope, $availableAt);

        if (null !== $options->key) {
            $this->idsByKey[$options->key] = $messageId;
        }

        return $messageId;
    }

    public function reserve(Duration $leaseDuration = new Duration(seconds: 30)): ?AsyncDelivery
    {
        $now = $this->clock->now();

        foreach ($this->messages as $id => $message) {
            if ($message['availableAt'] > $now) {
                continue;
            }

            if (null !== $message['leaseId'] && null !== $message['leasedUntil'] && $message['leasedUntil'] >= $now) {
                continue;
            }

            $messageId = new AsyncMessageId((string) $id);
            if ($message['attempts'] >= $this->retryPolicy->maxAttempts) {
                $this->quarantine(
                    new DeadLetter(
                        $messageId,
                        $message['envelope'],
                        $message['attempts'],
                        $now,
                        null,
                        'Delivery lease expired after its final attempt.',
                    ),
                );

                continue;
            }

            $leaseId = bin2hex(random_bytes(16));
            $leasedUntil = $now->add($leaseDuration);
            ++$message['attempts'];

            $this->messages[$id] = [
                ...$message,
                'leaseId' => $leaseId,
                'leasedUntil' => $leasedUntil,
            ];

            return new AsyncDelivery($messageId, $message['envelope'], $leaseId, $message['attempts'], $leasedUntil);
        }

        return null;
    }

    public function acknowledge(AsyncDelivery $delivery): void
    {
        $message = $this->messages[$delivery->messageId->value] ?? null;
        if (null === $message || $message['leaseId'] !== $delivery->leaseId) {
            return;
        }

        unset($this->messages[$delivery->messageId->value]);
        $this->removeKeyFor($delivery->messageId);
    }

    public function release(AsyncDelivery $delivery, ?Duration $delay = null, ?\Throwable $reason = null): void
    {
        $message = $this->messages[$delivery->messageId->value] ?? null;
        if (null === $message || $message['leaseId'] !== $delivery->leaseId) {
            return;
        }

        $now = $this->clock->now();
        if ($message['attempts'] >= $this->retryPolicy->maxAttempts) {
            $this->quarantine(
                new DeadLetter(
                    $delivery->messageId,
                    $message['envelope'],
                    $message['attempts'],
                    $now,
                    null === $reason ? null : $reason::class,
                    $reason?->getMessage() ?? 'Delivery was released after its final attempt.',
                ),
            );

            return;
        }

        if (null !== $reason) {
            $delay ??= $this->retryPolicy->retryDelay;
        }
        $this->messages[$delivery->messageId->value] = [
            ...$message,
            'availableAt' => $delay instanceof Duration ? $now->add($delay) : $now,
            'leaseId' => null,
            'leasedUntil' => null,
        ];
    }

    public function renew(AsyncDelivery $delivery, Duration $leaseDuration = new Duration(seconds: 30)): void
    {
        $message = $this->messages[$delivery->messageId->value] ?? null;
        if (null === $message || $message['leaseId'] !== $delivery->leaseId) {
            return;
        }

        $this->messages[$delivery->messageId->value] = [
            ...$message,
            'leasedUntil' => $this->clock->now()->add($leaseDuration),
        ];
    }

    public function isEmpty(): bool
    {
        return [] === $this->messages;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function countFailed(): int
    {
        return count($this->deadLetters);
    }

    /** @return list<DeadLetter<AsyncEnvelope>> */
    public function listFailed(int $limit = 100, int $offset = 0): array
    {
        if ($limit < 1 || $offset < 0) {
            throw new \InvalidArgumentException(
                'Dead letter pagination requires a positive limit and a non-negative offset.',
            );
        }

        $letters = array_values($this->deadLetters);
        usort($letters, static function (DeadLetter $a, DeadLetter $b): int {
            $byFailureTime = $a->failedAt <=> $b->failedAt;

            return 0 === $byFailureTime ? strcmp($a->messageId->value, $b->messageId->value) : $byFailureTime;
        });

        return array_slice($letters, $offset, $limit);
    }

    /** @return DeadLetter<AsyncEnvelope>|null */
    public function inspectFailed(AsyncMessageId $messageId): ?DeadLetter
    {
        return $this->deadLetters[$messageId->value] ?? null;
    }

    public function replayFailed(AsyncMessageId $messageId): bool
    {
        $deadLetter = $this->inspectFailed($messageId);
        if (null === $deadLetter) {
            return false;
        }

        $this->storeMessage($messageId, $deadLetter->envelope, $this->clock->now());
        unset($this->deadLetters[$messageId->value]);

        return true;
    }

    private function storeMessage(AsyncMessageId $messageId, AsyncEnvelope $envelope, DatePoint $availableAt): void
    {
        $this->messages[$messageId->value] = [
            'envelope' => $envelope,
            'availableAt' => $availableAt,
            'leaseId' => null,
            'leasedUntil' => null,
            'attempts' => 0,
        ];
    }

    /** @param DeadLetter<AsyncEnvelope> $deadLetter */
    private function quarantine(DeadLetter $deadLetter): void
    {
        $messageId = $deadLetter->messageId;
        $this->deadLetters[$messageId->value] = $deadLetter;
        unset($this->messages[$messageId->value]);
        $this->removeKeyFor($messageId);
    }

    private function removeKeyFor(AsyncMessageId $messageId): void
    {
        foreach ($this->idsByKey as $key => $id) {
            if ($id->value !== $messageId->value) {
                continue;
            }

            unset($this->idsByKey[$key]);
        }
    }
}
