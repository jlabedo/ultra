<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

final class TagNames
{
    /** The service named by this tag receives the participant's DBAL connection as its first argument. */
    public const string DBAL_CONNECTION_PROVIDER = 'ultra.unit_of_work.dbal_connection_provider';

    public const UNIT_OF_WORK = 'ultra_unit_of_work.unit_of_work';
}
