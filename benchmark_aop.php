<?php

/**
 * AOP Interceptor Performance Benchmark
 *
 * This script runs a comprehensive benchmark comparing runtime vs compiled
 * interceptor implementations across various scenarios.
 *
 * Usage: php benchmark_aop.php
 */

require_once 'vendor/autoload.php';

use Ultra\Aop\Tests\Benchmark\InterceptorBenchmark;

$benchmark = new InterceptorBenchmark();
$benchmark->run();
