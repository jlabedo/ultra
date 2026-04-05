<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

/**
 * The typed return value of an advice matcher closure.
 *
 * Returned from the static closure when the aspect applies to a handler.
 * Returning null from the closure means the aspect does not apply.
 *
 * @see Around
 * @see Before
 */
final readonly class MatchResult
{
    /**
     * @param array              $config   serializable runtime config embedded verbatim as a PHP
     *                                     literal in the generated dispatch method and passed as
     *                                     the last argument to the advice method at runtime
     * @param list<int>          $provides 0-based indices into the handler's non-message dep-param
     *                                     array that this around advice will supply at runtime.
     *                                     Those slots are excluded from the service locator; the
     *                                     advice passes them as extra arguments to $proceed instead.
     *                                     Always empty for before advices.
     * @param list<AdvicePhase>  $phases   phases where this advice should run.
     *                                     Defaults to [Execute] — backward compatible.
     *                                     Use [Dispatch] for advices that should run once at
     *                                     send/publish time regardless of sync/async.
     */
    private function __construct(
        public array $config = [],
        public array $provides = [],
        public array $phases = [AdvicePhase::Execute],
    ) {}

    /**
     * Signal that this aspect applies to the handler, with optional runtime config
     * and optional dep-param slots to claim.
     *
     * @param array             $config   serializable data for the runtime advice method
     * @param list<int>         $provides dep-param indices this advice will inject at runtime
     * @param list<AdvicePhase> $phases   phases where this advice runs (default: [Execute])
     */
    public static function apply(
        array $config = [],
        array $provides = [],
        array $phases = [AdvicePhase::Execute],
    ): self {
        return new self($config, $provides, $phases);
    }
}
