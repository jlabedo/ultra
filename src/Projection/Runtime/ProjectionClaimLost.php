<?php

declare(strict_types=1);

namespace Ultra\Projection;

/** @internal Aborts a batch transaction when the segment claim no longer matches. */
final class ProjectionClaimLost extends \RuntimeException {}
