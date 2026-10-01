<?php

declare(strict_types=1);

namespace Ultra\StateStore\Exception;

final class UnknownQueryGuardException extends \LogicException
{
    public function __construct(public readonly string $guardName)
    {
        parent::__construct(sprintf(
            'No query guard definition with watched states was registered for "%s". Declare it with #[DefineQueryGuard] or provide its definition to StateTableRegistry.',
            $guardName,
        ));
    }
}
