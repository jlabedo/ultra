<?php

declare(strict_types=1);

namespace Ultra\Projection\Backfill;

final readonly class ProcessProjectionBackfillExecutor implements ProjectionBackfillExecutorInterface
{
    public function __construct(
        private string $phpBinary = PHP_BINARY,
        private ?string $consolePath = null,
    ) {}

    public function run(array $jobs): array
    {
        $processClass = '\\Symfony\\Component\\Process\\Process';
        if (!class_exists($processClass)) {
            throw new \RuntimeException('The process backfill executor requires symfony/process. Install it to use projection:backfill with process workers.');
        }

        $consolePath = $this->consolePath ?? ($_SERVER['argv'][0] ?? 'bin/console');
        $processes = [];

        foreach ($jobs as $job) {
            $processes[] = [
                'job' => $job,
                'process' => new $processClass([
                    $this->phpBinary,
                    $consolePath,
                    'projection:backfill:segment',
                    $job->projectionId,
                    (string) $job->bucketFrom,
                    '--worker-id='.$job->workerId,
                    '--limit='.(string) $job->limit,
                ]),
            ];
        }

        foreach ($processes as $entry) {
            $entry['process']->start();
        }

        $results = [];
        foreach ($processes as $entry) {
            $entry['process']->wait();
            $results[] = new ProjectionBackfillProcessResult(
                $entry['job'],
                $entry['process']->getExitCode() ?? 1,
                $entry['process']->getOutput(),
                $entry['process']->getErrorOutput(),
            );
        }

        return $results;
    }
}
