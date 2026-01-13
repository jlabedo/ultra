<?php

namespace Ultra\Mapper\Exception;

class ValidationException extends \RuntimeException
{
    private array $errors;

    public function __construct(array $errors, string $message = 'Validation failed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
