<?php

declare(strict_types=1);

namespace App\Faculty\Read\Handler;

use App\Faculty\Query\GetStudentProfileByIdQuery;
use App\Faculty\State\FacultyReadModelState;
use App\Faculty\Support\FacultyIds;
use Ultra\DecisionEngine\Engine;
use Ultra\EventStore\DomainIdentifier;
use Ultra\Mediator\Attribute\Handles;

final readonly class GetStudentProfileByIdHandler
{
    public function __construct(
        private Engine $engine,
    ) {}

    #[Handles]
    public function handle(GetStudentProfileByIdQuery $query): mixed
    {
        $state = $this->engine->load(
            FacultyReadModelState::class,
            new DomainIdentifier('faculty', FacultyIds::DEFAULT),
        );

        return $state->student($query->studentId);
    }
}
