<?php

declare(strict_types=1);

namespace App\Faculty\Query;

use App\Faculty\Read\FacultyReadModelState;
use App\Faculty\Support\FacultyIds;
use Ultra\DecisionEngine\Engine;
use Ultra\EventStore\DomainIdentifier;
use Ultra\MessageHandling\Attribute\SelfHandler;

final readonly class GetFacultyOverviewQuery
{
    #[SelfHandler]
    public function handle(Engine $engine): mixed
    {
        $state = $engine->load(
            FacultyReadModelState::class,
            new DomainIdentifier('faculty', FacultyIds::DEFAULT),
        );

        return $state->overview();
    }
}
