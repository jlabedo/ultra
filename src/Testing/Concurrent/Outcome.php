<?php

declare(strict_types=1);

namespace Ultra\Testing\Concurrent;

final class Outcome
{
    private function __construct(
        private readonly bool $success,
        private readonly mixed $value,
        private readonly ?\Throwable $exception,
        private readonly string $exceptionClass,
        private readonly string $exceptionMessage,
    ) {}

    public static function success(mixed $value): self
    {
        return new self(true, $value, null, '', '');
    }

    public static function failure(\Throwable $exception): self
    {
        return new self(false, null, $exception, $exception::class, $exception->getMessage());
    }

    public static function failureFromDescription(
        string $class,
        string $message,
        ?\Throwable $exception = null,
    ): self {
        return new self(false, null, $exception, $class, $message);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function exception(): ?\Throwable
    {
        return $this->exception;
    }

    public function exceptionClass(): string
    {
        return $this->exceptionClass;
    }

    public function exceptionMessage(): string
    {
        return $this->exceptionMessage;
    }
}
