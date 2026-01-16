<?php

declare(strict_types=1);

namespace Ultra\Aop\Tests\Benchmark;

use Ultra\Aop\Interceptor;

class InterceptorBenchmark
{
    private const ITERATIONS = 100_000;
    private const WARMUP = 1000;

    public function run(): void
    {
        echo "=== Interceptor Performance Benchmark ===\n";
        echo "Iterations: " . number_format(self::ITERATIONS) . "\n\n";

        $results = [];

        // Benchmark 1: No interception (baseline)
        $results['baseline'] = $this->benchmarkBaseline();

        // Benchmark 2: Runtime interceptor - no advices
        $results['runtime_no_advices'] = $this->benchmarkRuntimeNoAdvices();

        // Benchmark 3: Compiled interceptor - no advices
        $results['compiled_no_advices'] = $this->benchmarkCompiledNoAdvices();

        // Benchmark 4: Runtime interceptor - single before advice
        $results['runtime_before'] = $this->benchmarkRuntimeBefore();

        // Benchmark 5: Compiled interceptor - single before advice
        $results['compiled_before'] = $this->benchmarkCompiledBefore();

        // Benchmark 6: Runtime interceptor - around advice
        $results['runtime_around'] = $this->benchmarkRuntimeAround();

        // Benchmark 7: Compiled interceptor - around advice
        $results['compiled_around'] = $this->benchmarkCompiledAround();

        // Benchmark 8: Runtime interceptor - all advice types
        $results['runtime_all'] = $this->benchmarkRuntimeAll();

        // Benchmark 9: Compiled interceptor - all advice types
        $results['compiled_all'] = $this->benchmarkCompiledAll();

        // Print results
        $this->printResults($results);
    }

    private function benchmarkBaseline(): array
    {
        $callable = fn(int $x) => $x * 2;

        // Warmup
        for ($i = 0; $i < self::WARMUP; $i++) {
            $callable(42);
        }

        $start = hrtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $callable(42);
        }
        $duration = (hrtime(true) - $start) / 1e9;

        return [
            'duration' => $duration,
            'per_call' => $duration / self::ITERATIONS * 1e6, // microseconds
        ];
    }

    private function benchmarkRuntimeNoAdvices(): array
    {
        $callable = fn(int $x) => $x * 2;
        $interceptor = new Interceptor($callable);

        // Warmup
        for ($i = 0; $i < self::WARMUP; $i++) {
            $interceptor->call(42);
        }

        $start = hrtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $interceptor->call(42);
        }
        $duration = (hrtime(true) - $start) / 1e9;

        return [
            'duration' => $duration,
            'per_call' => $duration / self::ITERATIONS * 1e6,
        ];
    }

    private function benchmarkCompiledNoAdvices(): array
    {
        $callable = fn(int $x) => $x * 2;
        $interceptor = new Interceptor($callable);
        $interceptor->compile();

        // Warmup
        for ($i = 0; $i < self::WARMUP; $i++) {
            $interceptor->call(42);
        }

        $start = hrtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $interceptor->call(42);
        }
        $duration = (hrtime(true) - $start) / 1e9;

        return [
            'duration' => $duration,
            'per_call' => $duration / self::ITERATIONS * 1e6,
        ];
    }

    private function benchmarkRuntimeBefore(): array
    {
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $interceptor = new Interceptor($callable);
        $interceptor->before(fn() => $counter++);

        // Warmup
        for ($i = 0; $i < self::WARMUP; $i++) {
            $interceptor->call(42);
        }

        $start = hrtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $interceptor->call(42);
        }
        $duration = (hrtime(true) - $start) / 1e9;

        return [
            'duration' => $duration,
            'per_call' => $duration / self::ITERATIONS * 1e6,
        ];
    }

    private function benchmarkCompiledBefore(): array
    {
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $interceptor = new Interceptor($callable);
        $interceptor->before(fn() => $counter++);
        $interceptor->compile();

        // Warmup
        for ($i = 0; $i < self::WARMUP; $i++) {
            $interceptor->call(42);
        }

        $start = hrtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $interceptor->call(42);
        }
        $duration = (hrtime(true) - $start) / 1e9;

        return [
            'duration' => $duration,
            'per_call' => $duration / self::ITERATIONS * 1e6,
        ];
    }

    private function benchmarkRuntimeAround(): array
    {
        $callable = fn(int $x) => $x * 2;
        $interceptor = new Interceptor($callable);
        $interceptor->around(fn(callable $proceed, int $x) => $proceed($x) + 1);

        // Warmup
        for ($i = 0; $i < self::WARMUP; $i++) {
            $interceptor->call(42);
        }

        $start = hrtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $interceptor->call(42);
        }
        $duration = (hrtime(true) - $start) / 1e9;

        return [
            'duration' => $duration,
            'per_call' => $duration / self::ITERATIONS * 1e6,
        ];
    }

    private function benchmarkCompiledAround(): array
    {
        $callable = fn(int $x) => $x * 2;
        $interceptor = new Interceptor($callable);
        $interceptor->around(fn(callable $proceed, int $x) => $proceed($x) + 1);
        $interceptor->compile();

        // Warmup
        for ($i = 0; $i < self::WARMUP; $i++) {
            $interceptor->call(42);
        }

        $start = hrtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $interceptor->call(42);
        }
        $duration = (hrtime(true) - $start) / 1e9;

        return [
            'duration' => $duration,
            'per_call' => $duration / self::ITERATIONS * 1e6,
        ];
    }

    private function benchmarkRuntimeAll(): array
    {
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $interceptor = new Interceptor($callable);
        $interceptor
            ->before(fn() => $counter++)
            ->around(fn(callable $proceed, int $x) => $proceed($x) + 1)
            ->afterReturning(fn($result) => $counter++)
            ->after(fn() => $counter++);

        // Warmup
        for ($i = 0; $i < self::WARMUP; $i++) {
            $interceptor->call(42);
        }

        $start = hrtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $interceptor->call(42);
        }
        $duration = (hrtime(true) - $start) / 1e9;

        return [
            'duration' => $duration,
            'per_call' => $duration / self::ITERATIONS * 1e6,
        ];
    }

    private function benchmarkCompiledAll(): array
    {
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $interceptor = new Interceptor($callable);
        $interceptor
            ->before(fn() => $counter++)
            ->around(fn(callable $proceed, int $x) => $proceed($x) + 1)
            ->afterReturning(fn($result) => $counter++)
            ->after(fn() => $counter++);
        $interceptor->compile();

        // Warmup
        for ($i = 0; $i < self::WARMUP; $i++) {
            $interceptor->call(42);
        }

        $start = hrtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $interceptor->call(42);
        }
        $duration = (hrtime(true) - $start) / 1e9;

        return [
            'duration' => $duration,
            'per_call' => $duration / self::ITERATIONS * 1e6,
        ];
    }

    private function printResults(array $results): void
    {
        echo "\n=== Results ===\n\n";

        $baseline = $results['baseline']['per_call'];

        $table = [
            ['Scenario', 'Time (s)', 'Per Call (μs)', 'vs Baseline', 'Overhead'],
        ];

        foreach ($results as $name => $data) {
            $overhead = $data['per_call'] - $baseline;
            $vsBaseline = $baseline > 0 ? sprintf('%.2fx', $data['per_call'] / $baseline) : 'N/A';

            $table[] = [
                $this->formatName($name),
                sprintf('%.4f', $data['duration']),
                sprintf('%.4f', $data['per_call']),
                $vsBaseline,
                sprintf('%.4f μs', $overhead),
            ];
        }

        $this->printTable($table);

        echo "\n=== Speedup Comparison ===\n\n";

        $comparisons = [
            ['No Advices', 'runtime_no_advices', 'compiled_no_advices'],
            ['Before Advice', 'runtime_before', 'compiled_before'],
            ['Around Advice', 'runtime_around', 'compiled_around'],
            ['All Advices', 'runtime_all', 'compiled_all'],
        ];

        foreach ($comparisons as [$label, $runtime, $compiled]) {
            if (!isset($results[$runtime]) || !isset($results[$compiled])) {
                continue;
            }

            $speedup = $results[$runtime]['per_call'] / $results[$compiled]['per_call'];
            $improvement = (($results[$runtime]['per_call'] - $results[$compiled]['per_call']) / $results[$runtime]['per_call']) * 100;

            printf(
                "%-20s: Compiled is %.2fx faster (%.1f%% improvement)\n",
                $label,
                $speedup,
                $improvement
            );
        }

        echo "\n";
    }

    private function formatName(string $name): string
    {
        $map = [
            'baseline' => 'Baseline (no interception)',
            'runtime_no_advices' => 'Runtime - No Advices',
            'compiled_no_advices' => 'Compiled - No Advices',
            'runtime_before' => 'Runtime - Before',
            'compiled_before' => 'Compiled - Before',
            'runtime_around' => 'Runtime - Around',
            'compiled_around' => 'Compiled - Around',
            'runtime_all' => 'Runtime - All Advices',
            'compiled_all' => 'Compiled - All Advices',
        ];

        return $map[$name] ?? $name;
    }

    private function printTable(array $table): void
    {
        // Calculate column widths
        $widths = [];
        foreach ($table[0] as $i => $header) {
            $widths[$i] = strlen($header);
        }

        foreach ($table as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen($cell));
            }
        }

        // Print header
        $this->printRow($table[0], $widths);
        echo str_repeat('-', array_sum($widths) + count($widths) * 3 - 1) . "\n";

        // Print data rows
        for ($i = 1; $i < count($table); $i++) {
            $this->printRow($table[$i], $widths);
        }
    }

    private function printRow(array $row, array $widths): void
    {
        foreach ($row as $i => $cell) {
            echo str_pad($cell, $widths[$i]) . '   ';
        }
        echo "\n";
    }
}
