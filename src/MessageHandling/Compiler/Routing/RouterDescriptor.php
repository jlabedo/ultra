<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Routing;

/**
 * Describes a discovered router interface for code generation.
 * Does not include generation details (class name, output dir) — those are
 * managed at a higher level by the compiler pass.
 */
final readonly class RouterDescriptor
{
    /**
     * @param \Closure(\ReflectionMethod): ?\Ultra\MessageHandling\Attribute\RouteMatch $matcher
     */
    public function __construct(
        /** FQCN of the router interface, e.g. EventBusInterface::class */
        public string $interfaceName,
        /** Name of the dispatch method, e.g. 'publish' or 'send' */
        public string $methodName,
        /** Name of the first parameter, e.g. 'event' or 'message' */
        public string $firstParamName,
        /** True for void-returning routers (EventBus), false for mixed (Mediator) */
        public bool $returnsVoid,
        /** Matcher closure from the dispatch attribute */
        public \Closure $matcher,
        /** True for SingleDispatch (one handler per message), false for MultiDispatch */
        public bool $isSingleDispatch,
        /** Resource tag used to discover known message classes; null for SingleDispatch */
        public ?string $messageTag,
        /** Exception class to throw when no handler is found */
        public string $noHandlerExceptionClass = \RuntimeException::class,
    ) {}
}
