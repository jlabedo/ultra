<?php

require_once 'vendor/autoload.php';

use Ultra\Aop\Interceptor;

echo "=== Compiled Interceptor Demo ===\n\n";

// Example 1: Message Handler with Logging
echo "1. Message Handler with Logging:\n";

$handleMessage = function(string $message) {
    echo "   Processing: {$message}\n";
    return "Processed: {$message}";
};

$interceptor = new Interceptor($handleMessage);
$interceptor
    ->before(function() { echo "   [LOG] Starting message handling...\n"; })
    ->before(function() { echo "   before 2\n"; })
    ->before(function() { echo "   before 3\n"; })
    ->around(fn (callable $proceed) => $proceed())
    ->around(fn (callable $proceed) => $proceed())
    ->afterReturning(function($result) { echo "   [LOG] Success: {$result}\n"; })
    ->afterReturning(function($result) { echo "   END"; })
    ->after(function() { echo "   [LOG] Completed\n"; })
    ->compile(); // Compile to optimized code!

$result = $interceptor->call("Hello World");
echo "\n";

// Example 2: Database Transaction Wrapper
echo "2. Database Transaction (simulated):\n";

$saveUser = function(array $user) {
    echo "   Saving user: {$user['name']}\n";
    return ['id' => 123, ...$user];
};

$dbInterceptor = new Interceptor($saveUser);
$dbInterceptor
    ->before(function() { echo "   [DB] BEGIN TRANSACTION\n"; })
    ->afterReturning(function() { echo "   [DB] COMMIT\n"; })
    ->afterThrowing(function($e) { echo "   [DB] ROLLBACK - Error: {$e->getMessage()}\n"; })
    ->compile();

$user = $dbInterceptor->call(['name' => 'John', 'email' => 'john@example.com']);
echo "   Result: ID={$user['id']}\n\n";

// Example 3: Performance Monitoring
echo "3. Performance Monitoring:\n";

$expensiveOperation = function(int $n) {
    usleep($n * 1000); // Simulate work
    return $n * 2;
};

$perfInterceptor = new Interceptor($expensiveOperation);
$perfInterceptor
    ->around(function(callable $proceed, int $n) {
        $start = microtime(true);
        $result = $proceed($n);
        $duration = (microtime(true) - $start) * 1000;
        echo "   [PERF] Operation took " . round($duration, 2) . "ms\n";
        return $result;
    })
    ->compile();

$result = $perfInterceptor->call(10);
echo "   Result: {$result}\n\n";

// Show generated code
echo "4. Generated Code Example:\n";
$files = glob('var/aop_cache/CompiledInterceptor_*.php');
if (!empty($files)) {
    echo "   Generated " . count($files) . " optimized interceptor classes\n";
    echo "   Example file: " . basename($files[0]) . "\n";
    echo "   Cache location: var/aop_cache/\n";
} else {
    echo "   No cached files found\n";
}

echo "\n=== Demo Complete ===\n";
