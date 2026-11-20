<?php

declare(strict_types=1);

namespace ComplianceExample;

use ComplianceExample\Refresh\EligibilityRefresh;
use Doctrine\DBAL\Connection;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\UnitOfWork\UnitOfWorkRunner;

/** Boots the application and exposes the entry points used by the demo and tests. */
final readonly class Runtime
{
    public Compliance $compliance;
    public ReferenceQueries $reference;
    public EligibilityQueries $queries;
    public UnitOfWorkRunner $work;
    private ProjectionCoordinator $projector;
    private EligibilityRefresh $refresh;

    public function __construct(
        Connection $connection,
        private int $subjectBatchSize = 100,
    ) {
        if ($subjectBatchSize < 1 || PHP_INT_MAX === $subjectBatchSize) {
            throw new \InvalidArgumentException('Subject batch size must be positive and leave room for a lookahead.');
        }

        $kernel = new Kernel('dev', true);
        $kernel->boot();
        $container = $kernel->getContainer();
        $container->set(Connection::class, $connection);
        $this->compliance = $container->get(Compliance::class);
        $this->reference = $container->get(ReferenceQueries::class);
        $this->queries = $container->get(EligibilityQueries::class);
        $this->work = $container->get(UnitOfWorkRunner::class);
        $this->projector = $container->get(ProjectionCoordinator::class);
        $this->refresh = $container->get(EligibilityRefresh::class);
    }

    /** Poll for committed projection activity, in batches of up to the given size. */
    public function project(int $batchSize = 100): int
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Projection batch size must be positive.');
        }

        return $this->projector->run(bin2hex(random_bytes(16)), $batchSize)->processedEvents;
    }

    /** Publish at most this many subject replacements or qualification pages. */
    public function refresh(string $account, int $maxSteps = 100): int
    {
        return $this->refresh->run($account, $maxSteps, $this->subjectBatchSize);
    }
}
