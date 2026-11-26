<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle;

final class TagNames
{
    /** On a projection service, names an ordered UnitOfWork participant via the service attribute. */
    public const string PERSISTENCE = 'ultra.projection.persistence';

    public const string PROJECTION = 'ultra.projection';
}
