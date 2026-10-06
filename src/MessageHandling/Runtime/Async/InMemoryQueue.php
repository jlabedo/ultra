<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\Time\ClockInterface;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final class InMemoryQueue implements AsyncMessageSender, AsyncMessageReceiver
{
    /**
     * @var array<string, array{
     *     envelope: AsyncEnvelope,
     *     availableAt: \DateTimeImmutable,
     *     leaseId: null|string,
     *     leasedUntil: null|\DateTimeImmutable,
     *     attempts: int
     * }>
     */
    private array $messages = [];

    /** @var array<string, AsyncMessageId> */
    private array $idsByKey = [];

    public function __construct(private ClockInterface $clock = new SystemClock()) {}

    public function enqueue(AsyncEnvelope $envelope, ?AsyncEnqueueOptions $options = null): AsyncMessageId
    {
        $options ??= new AsyncEnqueueOptions();
        $messageId = $options->messageId ?? AsyncMessageId::generate();
        $now = $this->clock->now();
        $availableAt = $options->availableAt ?? ($options->delay instanceof Duration ? $now->add($options->delay) : $now);

        if ($options->replace && null !== $options->key) {
            $existingId = $this->idsByKey[$options->key] ?? null;
            if (null !== $existingId) {
                unset($this->messages[$existingId->value], $this->idsByKey[$options->key]);
            }
        }

        $this->messages[$messageId->value] = [
            'envelope' => $envelope,
            'availableAt' => $availableAt,
            'leaseId' => null,
            'leasedUntil' => null,
            'attempts' => 0,
        ];

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

            $leaseId = bin2hex(random_bytes(16));
            $leasedUntil = $now->add($leaseDuration);
            ++$message['attempts'];

            $this->messages[$id] = [
                ...$message,
                'leaseId' => $leaseId,
                'leasedUntil' => $leasedUntil,
            ];

            return new AsyncDelivery(
                new AsyncMessageId($id),
                $message['envelope'],
                $leaseId,
                $message['attempts'],
                $leasedUntil,
            );
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

    private function removeKeyFor(AsyncMessageId $messageId): void
    {
        foreach ($this->idsByKey as $key => $id) {
            if ($id->value === $messageId->value) {
                unset($this->idsByKey[$key]);

                return;
            }
        }
    }
}
