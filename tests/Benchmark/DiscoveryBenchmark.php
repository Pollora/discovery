<?php

declare(strict_types=1);

namespace Tests\Benchmark;

use Illuminate\Container\Container;
use Pollora\Application\Domain\Contracts\DebugDetectorInterface;
use Pollora\Discovery\Domain\Models\DiscoveryContext;
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
    private readonly Container $container;

    private readonly DebugDetectorInterface $debugDetector;

    private readonly string $fixturesBasePath;

    /** @var array<string, array<string, mixed>> */
    private array $results = [];

    /** @var array<string, array<string, mixed>> */
    private array $resultsMulti = [];

    public function __construct()
    {
        $this->container = new Container;
        Container::setInstance($this->container);

        $this->debugDetector = new class implements DebugDetectorInterface
        {
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
     * @param  int[]  $classCounts  Class counts to benchmark
     * @param  int  $iterations  Number of iterations per benchmark for averaging
     */
    public function run(array $classCounts = [50, 100, 250, 500, 1000, 2000], int $iterations = 3): void
    {
        $this->printHeader();

        echo "\n  ── SINGLE LOCATION (flat directory) ──\n";
        foreach ($classCounts as $count) {
            $this->benchmarkClassCount($count, $iterations);
        }

        $this->printSummary();

        // Multi-location benchmark
        echo "\n  ── MULTI-LOCATION (modules with DDD subdirs) ──\n";
        $this->resultsMulti = [];
        $multiScenarios = [
            ['classes' => 100, 'modules' => 5],
            ['classes' => 250, 'modules' => 8],
            ['classes' => 500, 'modules' => 10],
            ['classes' => 1000, 'modules' => 15],
        ];

        foreach ($multiScenarios as $scenario) {
            $this->benchmarkMultiLocation($scenario['classes'], $scenario['modules'], $iterations);
        }

        $this->printMultiLocationSummary();
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
            'total_items' => [],
        ];

        for ($i = 0; $i < $iterations; $i++) {
            echo '  Iteration '.($i + 1).'...';

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
            $timings['total_items'][] = $fullResult['total_items'];

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
            'total_items' => (int) $this->average($timings['total_items']),
            'per_class_us' => ($this->average($timings['full_discovery']) * 1000) / $count,
        ];

        $r = $this->results[$count];
        echo "\n";
        echo sprintf("  Spatie scan:      avg %7.2f ms  (min %.2f / max %.2f)\n", $r['spatie_scan_avg_ms'], $r['spatie_scan_min_ms'], $r['spatie_scan_max_ms']);
        echo sprintf("  Full discovery:   avg %7.2f ms  (min %.2f / max %.2f)\n", $r['full_discovery_avg_ms'], $r['full_discovery_min_ms'], $r['full_discovery_max_ms']);
        echo sprintf("  Per class:        %7.1f us\n", $r['per_class_us']);
        echo sprintf("  Memory peak:      %7.2f MB\n", $r['memory_peak_mb']);
        echo sprintf("  Classes found:    %d\n", $r['classes_processed']);
        echo sprintf("  Attributes found: %d (avg %.1f/class)\n", $r['total_items'], $r['total_items'] / max(1, $r['classes_processed']));
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

        // Count total discovered items across all discoveries
        $totalItems = 0;
        foreach ($engine->getDiscoveries() as $discovery) {
            $totalItems += count($discovery->getItems());
        }

        return [
            'time_ms' => $elapsed,
            'memory_peak_mb' => ($memAfter - $memBefore) / (1024 * 1024),
            'classes_processed' => $stats['context']['total_classes'],
            'total_items' => $totalItems,
            'stats' => $stats,
        ];
    }

    private function benchmarkMultiLocation(int $totalClasses, int $moduleCount, int $iterations): void
    {
        $key = sprintf('%dc_%dm', $totalClasses, $moduleCount);
        echo "\n".str_repeat('─', 70)."\n";
        echo "  {$totalClasses} classes across {$moduleCount} modules ({$iterations} iterations)\n";
        echo str_repeat('─', 70)."\n";

        $generator = new FixtureGenerator(
            $this->fixturesBasePath,
            'Tests\\Benchmark\\Generated'
        );

        $multiInfo = $generator->generateMultiLocation($totalClasses, $moduleCount);
        echo sprintf("  Generated: %d classes, %d modules, %d subdirs\n",
            $multiInfo['total_classes'], $multiInfo['modules'], $multiInfo['dirs_created']);

        // Register autoloaders for all locations
        $autoloaders = [];
        foreach ($multiInfo['locations'] as $loc) {
            $autoloaders[] = $this->registerAutoloader($loc['path'], $loc['namespace']);
        }

        $timings = ['scan' => [], 'discovery' => [], 'memory' => [], 'items' => []];

        for ($i = 0; $i < $iterations; $i++) {
            echo '  Iteration '.($i + 1).'...';
            $this->clearStaticCaches();

            // Full discovery with multiple locations
            $engine = new DiscoveryEngine($this->container, $this->debugDetector);

            foreach ($multiInfo['locations'] as $loc) {
                $engine->addLocation(new DiscoveryLocation($loc['namespace'], $loc['path']));
            }

            $engine->addDiscovery('hooks', new BenchmarkDiscovery('hooks'));
            $engine->addDiscovery('providers', new BenchmarkDiscovery('providers'));
            $engine->addDiscovery('post_types', new BenchmarkDiscovery('post_types'));
            $engine->addDiscovery('rest_routes', new BenchmarkDiscovery('rest_routes'));
            $engine->addDiscovery('schedules', new BenchmarkDiscovery('schedules'));

            $memBefore = memory_get_usage(true);
            $start = hrtime(true);
            $engine->discover();
            $elapsed = (hrtime(true) - $start) / 1_000_000;

            $totalItems = 0;
            foreach ($engine->getDiscoveries() as $d) {
                $totalItems += count($d->getItems());
            }

            $timings['scan'][] = $elapsed; // Combined scan + processing
            $timings['memory'][] = (memory_get_peak_usage(true) - $memBefore) / (1024 * 1024);
            $timings['items'][] = $totalItems;

            echo " done\n";
        }

        foreach ($autoloaders as $al) {
            spl_autoload_unregister($al);
        }

        $generator->cleanup();

        $avgMs = $this->average($timings['scan']);
        $this->resultsMulti[$key] = [
            'classes' => $multiInfo['total_classes'],
            'modules' => $multiInfo['modules'],
            'dirs' => $multiInfo['dirs_created'],
            'avg_ms' => $avgMs,
            'min_ms' => min($timings['scan']),
            'max_ms' => max($timings['scan']),
            'per_class_us' => ($avgMs * 1000) / max(1, $multiInfo['total_classes']),
            'items' => (int) $this->average($timings['items']),
            'memory_mb' => $this->average($timings['memory']),
        ];

        $r = $this->resultsMulti[$key];
        echo sprintf("\n  Discovery:     avg %7.2f ms  (min %.2f / max %.2f)\n", $r['avg_ms'], $r['min_ms'], $r['max_ms']);
        echo sprintf("  Per class:     %7.1f us\n", $r['per_class_us']);
        echo sprintf("  Attrs found:   %d\n", $r['items']);
        echo sprintf("  Memory peak:   %7.2f MB\n", $r['memory_mb']);
    }

    private function printMultiLocationSummary(): void
    {
        if ($this->resultsMulti === []) {
            return;
        }

        echo "\n\n";
        echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
        echo "║                   MULTI-LOCATION SUMMARY                                  ║\n";
        echo "╠════════════════════════════════════════════════════════════════════════════╣\n";
        echo sprintf("║  %-7s │ %-7s │ %-5s │ %-11s │ %-9s │ %-6s │ %-6s ║\n",
            'Classes', 'Modules', 'Dirs', 'Discov (ms)', 'Per cls', 'Attrs', 'Mem MB');
        echo "╠════════════════════════════════════════════════════════════════════════════╣\n";

        foreach ($this->resultsMulti as $r) {
            echo sprintf(
                "║  %-7d │ %-7d │ %-5d │ %11.2f │ %6.1f us │ %6d │ %5.2f  ║\n",
                $r['classes'], $r['modules'], $r['dirs'],
                $r['avg_ms'], $r['per_class_us'], $r['items'], $r['memory_mb']
            );
        }

        echo "╚════════════════════════════════════════════════════════════════════════════╝\n";

        // Compare single vs multi for same class count
        echo "\n  Single-location vs Multi-location comparison:\n";
        foreach ($this->resultsMulti as $r) {
            $classCount = $r['classes'];
            // Find closest single-location result
            $closest = null;
            $closestDiff = PHP_INT_MAX;
            foreach ($this->results as $sr) {
                $diff = abs($sr['count'] - $classCount);
                if ($diff < $closestDiff) {
                    $closestDiff = $diff;
                    $closest = $sr;
                }
            }

            if ($closest && $closestDiff < $classCount * 0.3) {
                $overhead = (($r['avg_ms'] / max(0.01, $closest['full_discovery_avg_ms'])) - 1) * 100;
                echo sprintf("    %d classes: single=%.1fms, multi(%d modules)=%.1fms => %+.0f%% overhead\n",
                    $classCount, $closest['full_discovery_avg_ms'], $r['modules'], $r['avg_ms'], $overhead);
            }
        }

        echo "\n";
    }

    private function registerAutoloader(string $path, string $namespace): \Closure
    {
        $autoloader = function (string $class) use ($path, $namespace): void {
            if (! str_starts_with($class, $namespace)) {
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
        echo '  PHP '.phpversion().' | Memory limit: '.ini_get('memory_limit')."\n";
    }

    private function printDistribution(array $distribution): void
    {
        $parts = [];
        foreach ($distribution as $type => $count) {
            $parts[] = sprintf('%s: %s', $type, $count);
        }

        echo '  Distribution: '.implode(', ', $parts)."\n";
    }

    private function printSummary(): void
    {
        echo "\n\n";
        echo "╔══════════════════════════════════════════════════════════════════════╗\n";
        echo "║                          SUMMARY TABLE                              ║\n";
        echo "╠══════════════════════════════════════════════════════════════════════╣\n";
        echo sprintf("║  %-7s │ %-10s │ %-12s │ %-9s │ %-6s │ %-6s ║\n", 'Classes', 'Scan (ms)', 'Discov (ms)', 'Per cls', 'Attrs', 'Mem MB');
        echo "╠══════════════════════════════════════════════════════════════════════╣\n";

        foreach ($this->results as $r) {
            echo sprintf(
                "║  %-7d │ %10.2f │ %12.2f │ %6.1f us │ %6d │ %5.2f  ║\n",
                $r['count'],
                $r['spatie_scan_avg_ms'],
                $r['full_discovery_avg_ms'],
                $r['per_class_us'],
                $r['total_items'],
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

            echo sprintf("    %dx classes => %.1fx time (ideal linear = %.1fx)\n", (int) $classRatio, $timeRatio, $classRatio);

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
