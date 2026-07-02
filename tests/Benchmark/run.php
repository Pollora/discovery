<?php

declare(strict_types=1);

/**
 * Discovery Performance Benchmark Runner
 *
 * Usage:
 *   php tests/Benchmark/run.php                    # Default: 50, 100, 250, 500, 1000, 2000
 *   php tests/Benchmark/run.php 100 500 1000       # Custom class counts
 *   php tests/Benchmark/run.php --quick             # Quick: 50, 100, 250
 *   php tests/Benchmark/run.php --stress            # Stress: 1000, 2000, 5000
 */

require_once __DIR__.'/../../vendor/autoload.php';

// Register benchmark namespace autoloader
spl_autoload_register(function (string $class): void {
    $prefix = 'Tests\\Benchmark\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace($prefix, '', $class);
    $file = __DIR__.'/'.str_replace('\\', '/', $relative).'.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Tests\Benchmark\DiscoveryBenchmark;

// Parse arguments
$args = array_slice($argv, 1);
$iterations = 3;

if (in_array('--quick', $args, true)) {
    $classCounts = [50, 100, 250];
    $args = array_diff($args, ['--quick']);
} elseif (in_array('--stress', $args, true)) {
    $classCounts = [1000, 2000, 5000];
    $iterations = 2;
    $args = array_diff($args, ['--stress']);
} elseif ($args !== []) {
    $classCounts = array_map('intval', array_filter($args, 'is_numeric'));
    if ($classCounts === []) {
        $classCounts = [50, 100, 250, 500, 1000, 2000];
    }
} else {
    $classCounts = [50, 100, 250, 500, 1000, 2000];
}

$benchmark = new DiscoveryBenchmark();
$benchmark->run($classCounts, $iterations);
