<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Attribute;

/**
 * Tells where a state parameter's id comes from, instead of the flavor's naming convention.
 *
 * - `#[BindTo('property.path')]` reads a message property path.
 * - `#[BindTo(method: 'name')]` calls a public method of the message. The method's parameters are other states of
 *   the same handler, matched by parameter name, so an id can depend on a loaded state.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class BindTo
{
    public function __construct(
        public ?string $property = null,
        public ?string $method = null,
    ) {
        if ((null === $property) === (null === $method)) {
            throw new \LogicException('#[BindTo] requires exactly one of a property path or a method.');
        }
    }
}
