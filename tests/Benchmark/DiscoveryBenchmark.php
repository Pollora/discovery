<?php

declare(strict_types=1);

namespace Tests\Benchmark;

use Illuminate\Container\Container;
use Pollora\Application\Domain\Contracts\DebugDetectorInterface;
use Pollora\Discovery\Domain\Models\DiscoveryContext;
use Pollora\Discovery\Domain\Models\DiscoveryItems;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\DiscoveryCacheManager;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Tests\Benchmark\Fixtures\FixtureGenerator;

/**
 * Discovery Benchmark Runner
 *
 * Measures performance of the Discovery system at various class counts.
 * Tests both the Spatie scan phase and the per-class processing phase independently.
 */
final class DiscoveryBenchmark
{
    private Container $container;

    private DebugDetectorInterface $debugDetector;

    private string $fixturesBasePath;

    /** @var array<string, array<string, mixed>> */
    private array $results = [];

    public function __construct()
    {
        $this->container = new Container;
        Container::setInstance($this->container);

        $this->debugDetector = new class implements DebugDetectorInterface {
            public function isDebugMode(): bool
            {
                return true; // Force no persistent cache
            }
        };

        $this->fixturesBasePath = __DIR__.'/Generated';
    }

    /**
     * Run the full benchmark suite.
     *
     * @param int[] $classCounts Class counts to benchmark
     * @param int $iterations Number of iterations per benchmark for averaging
     */
    public function run(array $classCounts = [50, 100, 250, 500, 1000, 2000], int $iterations = 3): void
    {
        $this->printHeader();

        foreach ($classCounts as $count) {
            $this->benchmarkClassCount($count, $iterations);
        }

        $this->printSummary();
    }

    private function benchmarkClassCount(int $count, int $iterations): void
    {
        echo "\n".str_repeat('─', 70)."\n";
        echo "  Benchmarking with {$count} classes ({$iterations} iterations)\n";
        echo str_repeat('─', 70)."\n";

        // Generate fixtures
        $generator = new FixtureGenerator(
            $this->fixturesBasePath,
            'Tests\\Benchmark\\Generated'
        );

        $fixtureInfo = $generator->generate($count);
        $this->printDistribution($fixtureInfo['distribution']);

        // Register autoloader for generated classes
        $autoloader = $this->registerAutoloader($fixtureInfo['path'], $fixtureInfo['namespace']);

        $timings = [
            'spatie_scan' => [],
            'full_discovery' => [],
            'memory_peak' => [],
            'classes_processed' => [],
        ];

        for ($i = 0; $i < $iterations; $i++) {
            echo "  Iteration ".($i + 1)."...";

            // Clear any static caches between iterations
            $this->clearStaticCaches();

            // Benchmark: Spatie scan phase only
            $scanResult = $this->benchmarkSpatieScan($fixtureInfo);
            $timings['spatie_scan'][] = $scanResult['time_ms'];

            // Clear static caches again
            $this->clearStaticCaches();

            // Benchmark: Full discovery (scan + process for all discovery types)
            $fullResult = $this->benchmarkFullDiscovery($fixtureInfo);
            $timings['full_discovery'][] = $fullResult['time_ms'];
            $timings['memory_peak'][] = $fullResult['memory_peak_mb'];
            $timings['classes_processed'][] = $fullResult['classes_processed'];

            echo " done\n";
        }

        // Unregister autoloader
        spl_autoload_unregister($autoloader);

        // Cleanup generated files
        $generator->cleanup();

        // Store averaged results
        $this->results[$count] = [
            'count' => $count,
            'spatie_scan_avg_ms' => $this->average($timings['spatie_scan']),
            'spatie_scan_min_ms' => min($timings['spatie_scan']),
            'spatie_scan_max_ms' => max($timings['spatie_scan']),
            'full_discovery_avg_ms' => $this->average($timings['full_discovery']),
            'full_discovery_min_ms' => min($timings['full_discovery']),
            'full_discovery_max_ms' => max($timings['full_discovery']),
            'memory_peak_mb' => $this->average($timings['memory_peak']),
            'classes_processed' => (int) $this->average($timings['classes_processed']),
            'per_class_us' => ($this->average($timings['full_discovery']) * 1000) / $count,
        ];

        $r = $this->results[$count];
        echo "\n";
        echo sprintf("  Spatie scan:      avg %7.2f ms  (min %.2f / max %.2f)\n", $r['spatie_scan_avg_ms'], $r['spatie_scan_min_ms'], $r['spatie_scan_max_ms']);
        echo sprintf("  Full discovery:   avg %7.2f ms  (min %.2f / max %.2f)\n", $r['full_discovery_avg_ms'], $r['full_discovery_min_ms'], $r['full_discovery_max_ms']);
        echo sprintf("  Per class:        %7.1f us\n", $r['per_class_us']);
        echo sprintf("  Memory peak:      %7.2f MB\n", $r['memory_peak_mb']);
        echo sprintf("  Classes found:    %d\n", $r['classes_processed']);
    }

    /**
     * Benchmark: Spatie structure scan only (no discovery processing).
     *
     * @return array{time_ms: float, structures_count: int}
     */
    private function benchmarkSpatieScan(array $fixtureInfo): array
    {
        $cacheManager = new DiscoveryCacheManager($this->container, $this->debugDetector);
        $reflectionCache = new ReflectionCache($this->container);
        $context = new DiscoveryContext($reflectionCache);

        $location = new DiscoveryLocation(
            $fixtureInfo['namespace'],
            $fixtureInfo['path']
        );

        $start = hrtime(true);
        $structures = $cacheManager->getStructuresForLocation($location, $context);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        return [
            'time_ms' => $elapsed,
            'structures_count' => count($structures),
        ];
    }

    /**
     * Benchmark: Full discovery with multiple discovery types.
     *
     * Uses a lightweight HookDiscovery mock to test the real DiscoveryEngine
     * processing pipeline without depending on WordPress runtime.
     *
     * @return array{time_ms: float, memory_peak_mb: float, classes_processed: int, stats: array}
     */
    private function benchmarkFullDiscovery(array $fixtureInfo): array
    {
        $engine = new DiscoveryEngine(
            $this->container,
            $this->debugDetector
        );

        $location = new DiscoveryLocation(
            $fixtureInfo['namespace'],
            $fixtureInfo['path']
        );

        // Add multiple discovery types to simulate realistic load
        $engine->addLocation($location);
        $engine->addDiscovery('hooks', new BenchmarkDiscovery('hooks'));
        $engine->addDiscovery('providers', new BenchmarkDiscovery('providers'));
        $engine->addDiscovery('post_types', new BenchmarkDiscovery('post_types'));
        $engine->addDiscovery('rest_routes', new BenchmarkDiscovery('rest_routes'));
        $engine->addDiscovery('schedules', new BenchmarkDiscovery('schedules'));

        $memBefore = memory_get_usage(true);
        $start = hrtime(true);

        $engine->discover();

        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $memAfter = memory_get_peak_usage(true);

        $stats = $engine->getPerformanceStats();

        return [
            'time_ms' => $elapsed,
            'memory_peak_mb' => ($memAfter - $memBefore) / (1024 * 1024),
            'classes_processed' => $stats['context']['total_classes'],
            'stats' => $stats,
        ];
    }

    private function registerAutoloader(string $path, string $namespace): \Closure
    {
        $autoloader = function (string $class) use ($path, $namespace): void {
            if (!str_starts_with($class, $namespace)) {
                return;
            }

            $relative = str_replace($namespace.'\\', '', $class);
            $file = $path.'/'.str_replace('\\', '/', $relative).'.php';

            if (file_exists($file)) {
                require_once $file;
            }
        };

        spl_autoload_register($autoloader);

        return $autoloader;
    }

    private function clearStaticCaches(): void
    {
        // Use reflection to clear DiscoveryCacheManager's static cache
        $ref = new \ReflectionClass(DiscoveryCacheManager::class);
        $prop = $ref->getProperty('structuresCache');
        $prop->setValue(null, []);
    }

    private function average(array $values): float
    {
        return array_sum($values) / count($values);
    }

    private function printHeader(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════════╗\n";
        echo "║           Pollora Discovery System — Performance Benchmark          ║\n";
        echo "╠══════════════════════════════════════════════════════════════════════╣\n";
        echo "║  Tests discovery WITHOUT persistent cache (debug mode).             ║\n";
        echo "║  Measures: Spatie scan, full discovery pipeline, per-class cost.    ║\n";
        echo "╚══════════════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        echo "  PHP ".phpversion()." | Memory limit: ".ini_get('memory_limit')."\n";
    }

    private function printDistribution(array $distribution): void
    {
        $parts = [];
        foreach ($distribution as $type => $count) {
            $parts[] = "{$type}: {$count}";
        }
        echo "  Distribution: ".implode(', ', $parts)."\n";
    }

    private function printSummary(): void
    {
        echo "\n\n";
        echo "╔══════════════════════════════════════════════════════════════════════╗\n";
        echo "║                          SUMMARY TABLE                              ║\n";
        echo "╠══════════════════════════════════════════════════════════════════════╣\n";
        echo sprintf("║  %-8s │ %-12s │ %-14s │ %-10s │ %-8s ║\n", 'Classes', 'Scan (ms)', 'Discovery (ms)', 'Per cls', 'Mem MB');
        echo "╠══════════════════════════════════════════════════════════════════════╣\n";

        foreach ($this->results as $r) {
            echo sprintf(
                "║  %-8d │ %12.2f │ %14.2f │ %7.1f us │ %6.2f   ║\n",
                $r['count'],
                $r['spatie_scan_avg_ms'],
                $r['full_discovery_avg_ms'],
                $r['per_class_us'],
                $r['memory_peak_mb']
            );
        }

        echo "╚══════════════════════════════════════════════════════════════════════╝\n";

        // Scaling analysis
        if (count($this->results) >= 2) {
            echo "\n  Scaling analysis:\n";
            $keys = array_keys($this->results);
            $first = $this->results[$keys[0]];
            $last = $this->results[$keys[count($keys) - 1]];
            $classRatio = $last['count'] / $first['count'];
            $timeRatio = $last['full_discovery_avg_ms'] / max(0.01, $first['full_discovery_avg_ms']);

            echo sprintf("    %dx classes => %.1fx time (ideal linear = %.1fx)\n", (int)$classRatio, $timeRatio, $classRatio);

            if ($timeRatio > $classRatio * 1.5) {
                echo "    => Super-linear scaling detected — optimization opportunities likely\n";
            } elseif ($timeRatio <= $classRatio * 1.1) {
                echo "    => Linear scaling — discovery scales well\n";
            } else {
                echo "    => Near-linear scaling — acceptable\n";
            }
        }

        echo "\n";
    }
}
