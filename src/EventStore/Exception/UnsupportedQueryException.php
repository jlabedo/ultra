<?php

declare(strict_types=1);

namespace Ultra\EventStore\Exception;

/**
 * Thrown when a store-backed query shape cannot be represented safely.
 */
final class UnsupportedQueryException extends \InvalidArgumentException {}
