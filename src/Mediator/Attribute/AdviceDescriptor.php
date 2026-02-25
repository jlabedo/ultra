<?php

declare(strict_types=1);

namespace Ultra\Mediator\Attribute;

/**
 * Describes a single advice: the type of advice, the service to call, and the method name.
 *
 * Method signatures expected by the mediator compiler per advice type:
 *
 *   Before:  method(object $message, array $context): void
 *   Around:  method(callable $proceed, object $message, array $context): mixed
 *              — $proceed is fn(object $message, array $context): mixed
 */
final readonly class AdviceDescriptor
{
    public function __construct(
        public AdviceType $type,
        public string $serviceId,
        public string $method,
    ) {
    }

    public static function before(string $serviceId, string $method): self
    {
        return new self(AdviceType::Before, $serviceId, $method);
    }

    public static function around(string $serviceId, string $method): self
    {
        return new self(AdviceType::Around, $serviceId, $method);
    }
}
