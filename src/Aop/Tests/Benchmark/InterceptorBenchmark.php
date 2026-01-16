<?php

declare(strict_types=1);

namespace Ultra\Aop\Tests\Benchmark;

use Ultra\Aop\CallableReference;
use Ultra\Aop\Interceptor;
use Ultra\Aop\Tests\ClosureCallable;
use Ultra\Aop\Tests\TestContainer;

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

        // Benchmark 8: Runtime interceptor - afterReturning advice
        $results['runtime_afterReturning'] = $this->benchmarkRuntimeAfterReturning();

        // Benchmark 9: Compiled interceptor - afterReturning advice
        $results['compiled_afterReturning'] = $this->benchmarkCompiledAfterReturning();

        // Benchmark 10: Runtime interceptor - after advice
        $results['runtime_after'] = $this->benchmarkRuntimeAfter();

        // Benchmark 11: Compiled interceptor - after advice
        $results['compiled_after'] = $this->benchmarkCompiledAfter();

        // Benchmark 12: Runtime interceptor - multiple before advices
        $results['runtime_multiple_before'] = $this->benchmarkRuntimeMultipleBefore();

        // Benchmark 13: Compiled interceptor - multiple before advices
        $results['compiled_multiple_before'] = $this->benchmarkCompiledMultipleBefore();

        // Benchmark 14: Runtime interceptor - all advice types
        $results['runtime_all'] = $this->benchmarkRuntimeAll();

        // Benchmark 15: Compiled interceptor - all advice types
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
        $container = new TestContainer();
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );

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
        $container = new TestContainer();
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
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
        $container = new TestContainer();
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('before', new ClosureCallable(fn() => $counter++));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor->before(CallableReference::fromContainer('before', ClosureCallable::class, 'invoke'));

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
        $container = new TestContainer();
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('before', new ClosureCallable(fn() => $counter++));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor->before(CallableReference::fromContainer('before', ClosureCallable::class, 'invoke'));
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
        $container = new TestContainer();
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('around', new ClosureCallable(fn(callable $proceed, int $x) => $proceed($x) + 1));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor->around(CallableReference::fromContainer('around', ClosureCallable::class, 'invoke'));

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
        $container = new TestContainer();
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('around', new ClosureCallable(fn(callable $proceed, int $x) => $proceed($x) + 1));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor->around(CallableReference::fromContainer('around', ClosureCallable::class, 'invoke'));
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

    private function benchmarkRuntimeAfterReturning(): array
    {
        $container = new TestContainer();
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('afterReturning', new ClosureCallable(fn($result) => $counter += $result));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor->afterReturning(CallableReference::fromContainer('afterReturning', ClosureCallable::class, 'invoke'));

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

    private function benchmarkCompiledAfterReturning(): array
    {
        $container = new TestContainer();
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('afterReturning', new ClosureCallable(fn($result) => $counter += $result));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor->afterReturning(CallableReference::fromContainer('afterReturning', ClosureCallable::class, 'invoke'));
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

    private function benchmarkRuntimeAfter(): array
    {
        $container = new TestContainer();
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('after', new ClosureCallable(fn() => $counter++));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor->after(CallableReference::fromContainer('after', ClosureCallable::class, 'invoke'));

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

    private function benchmarkCompiledAfter(): array
    {
        $container = new TestContainer();
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('after', new ClosureCallable(fn() => $counter++));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor->after(CallableReference::fromContainer('after', ClosureCallable::class, 'invoke'));
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

    private function benchmarkRuntimeMultipleBefore(): array
    {
        $container = new TestContainer();
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('before1', new ClosureCallable(fn() => $counter++));
        $container->set('before2', new ClosureCallable(fn() => $counter++));
        $container->set('before3', new ClosureCallable(fn() => $counter++));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor
            ->before(CallableReference::fromContainer('before1', ClosureCallable::class, 'invoke'))
            ->before(CallableReference::fromContainer('before2', ClosureCallable::class, 'invoke'))
            ->before(CallableReference::fromContainer('before3', ClosureCallable::class, 'invoke'));

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

    private function benchmarkCompiledMultipleBefore(): array
    {
        $container = new TestContainer();
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('before1', new ClosureCallable(fn() => $counter++));
        $container->set('before2', new ClosureCallable(fn() => $counter++));
        $container->set('before3', new ClosureCallable(fn() => $counter++));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor
            ->before(CallableReference::fromContainer('before1', ClosureCallable::class, 'invoke'))
            ->before(CallableReference::fromContainer('before2', ClosureCallable::class, 'invoke'))
            ->before(CallableReference::fromContainer('before3', ClosureCallable::class, 'invoke'));
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
        $container = new TestContainer();
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('before', new ClosureCallable(fn() => $counter++));
        $container->set('around', new ClosureCallable(fn(callable $proceed, int $x) => $proceed($x) + 1));
        $container->set('afterReturning', new ClosureCallable(fn($result) => $counter++));
        $container->set('after', new ClosureCallable(fn() => $counter++));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor
            ->before(CallableReference::fromContainer('before', ClosureCallable::class, 'invoke'))
            ->around(CallableReference::fromContainer('around', ClosureCallable::class, 'invoke'))
            ->afterReturning(CallableReference::fromContainer('afterReturning', ClosureCallable::class, 'invoke'))
            ->after(CallableReference::fromContainer('after', ClosureCallable::class, 'invoke'));

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
        $container = new TestContainer();
        $counter = 0;
        $callable = fn(int $x) => $x * 2;
        $container->set('target', new ClosureCallable($callable));
        $container->set('before', new ClosureCallable(fn() => $counter++));
        $container->set('around', new ClosureCallable(fn(callable $proceed, int $x) => $proceed($x) + 1));
        $container->set('afterReturning', new ClosureCallable(fn($result) => $counter++));
        $container->set('after', new ClosureCallable(fn() => $counter++));
        
        $interceptor = new Interceptor(
            CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
            $container
        );
        $interceptor
            ->before(CallableReference::fromContainer('before', ClosureCallable::class, 'invoke'))
            ->around(CallableReference::fromContainer('around', ClosureCallable::class, 'invoke'))
            ->afterReturning(CallableReference::fromContainer('afterReturning', ClosureCallable::class, 'invoke'))
            ->after(CallableReference::fromContainer('after', ClosureCallable::class, 'invoke'));
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
            ['AfterReturning Advice', 'runtime_afterReturning', 'compiled_afterReturning'],
            ['After Advice', 'runtime_after', 'compiled_after'],
            ['Around Advice', 'runtime_around', 'compiled_around'],
            ['Multiple Before', 'runtime_multiple_before', 'compiled_multiple_before'],
            ['All Advices', 'runtime_all', 'compiled_all'],
        ];

        $speedups = [];
        foreach ($comparisons as [$label, $runtime, $compiled]) {
            if (!isset($results[$runtime]) || !isset($results[$compiled])) {
                continue;
            }

            $speedup = $results[$runtime]['per_call'] / $results[$compiled]['per_call'];
            $improvement = (($results[$runtime]['per_call'] - $results[$compiled]['per_call']) / $results[$runtime]['per_call']) * 100;
            $speedups[] = ['label' => $label, 'speedup' => $speedup, 'improvement' => $improvement];

            printf(
                "%-25s: Compiled is %.2fx faster (%.1f%% improvement)\n",
                $label,
                $speedup,
                $improvement
            );
        }

        // Summary statistics
        if (!empty($speedups)) {
            $avgSpeedup = array_sum(array_column($speedups, 'speedup')) / count($speedups);
            $maxSpeedup = max(array_column($speedups, 'speedup'));
            $minSpeedup = min(array_column($speedups, 'speedup'));
            $maxLabel = $speedups[array_search($maxSpeedup, array_column($speedups, 'speedup'))]['label'];
            $minLabel = $speedups[array_search($minSpeedup, array_column($speedups, 'speedup'))]['label'];

            echo "\n=== Summary ===\n\n";
            printf("Average speedup: %.2fx\n", $avgSpeedup);
            printf("Best speedup: %.2fx (%s)\n", $maxSpeedup, $maxLabel);
            printf("Lowest speedup: %.2fx (%s)\n", $minSpeedup, $minLabel);
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
            'runtime_afterReturning' => 'Runtime - AfterReturning',
            'compiled_afterReturning' => 'Compiled - AfterReturning',
            'runtime_after' => 'Runtime - After',
            'compiled_after' => 'Compiled - After',
            'runtime_around' => 'Runtime - Around',
            'compiled_around' => 'Compiled - Around',
            'runtime_multiple_before' => 'Runtime - Multiple Before',
            'compiled_multiple_before' => 'Compiled - Multiple Before',
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
