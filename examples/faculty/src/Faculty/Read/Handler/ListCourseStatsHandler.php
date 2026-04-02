<?php

declare(strict_types=1);

namespace App\Faculty\Read\Handler;

use App\Faculty\Query\ListCourseStatsQuery;
use App\Faculty\State\FacultyReadModelState;
use App\Faculty\Support\FacultyIds;
use Ultra\DecisionEngine\Engine;
use Ultra\EventStore\DomainIdentifier;
use Ultra\MessageHandling\Attribute\Handles;

final readonly class ListCourseStatsHandler
{
    public function __construct(
        private Engine $engine,
    ) {}

    #[Handles]
    public function handle(ListCourseStatsQuery $query): array
    {
        $state = $this->engine->load(
            FacultyReadModelState::class,
            new DomainIdentifier('faculty', FacultyIds::DEFAULT),
        );

        return $state->courses();
    }
}
