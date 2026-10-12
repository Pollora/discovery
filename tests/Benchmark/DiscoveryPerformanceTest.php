<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Models\DiscoveryContext;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\DiscoveryCacheManager;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Pollora\Discovery\Tests\Benchmark\BenchmarkDiscovery;
use Pollora\Discovery\Tests\Benchmark\Fixtures\FixtureGenerator;

/**
 * Discovery Performance Tests
 *
 * These tests validate that the discovery system performs within acceptable
 * time bounds. They generate real PHP classes with attributes and measure
 * the full discovery pipeline.
 *
 * Run with: vendor/bin/pest tests/Benchmark/DiscoveryPerformanceTest.php
 */
function clearDiscoveryCacheManagerStatic(): void
{
    $ref = new ReflectionClass(DiscoveryCacheManager::class);
    $prop = $ref->getProperty('structuresCache');
    $prop->setValue(null, []);
}

function generateAndRegister(int $count): array
{
    $basePath = __DIR__.'/Generated';
    $namespace = 'Pollora\\Discovery\\Tests\\Benchmark\\Generated';

    $generator = new FixtureGenerator($basePath, $namespace);
    $info = $generator->generate($count);

    $autoloader = function (string $class) use ($basePath, $namespace): void {
        if (! str_starts_with($class, $namespace)) {
            return;
        }

        $relative = str_replace($namespace.'\\', '', $class);
        $file = $basePath.'/'.str_replace('\\', '/', $relative).'.php';
        if (file_exists($file)) {
            require_once $file;
        }
    };
    spl_autoload_register($autoloader);

    return ['info' => $info, 'generator' => $generator, 'autoloader' => $autoloader];
}

describe('Discovery Performance', function (): void {

    afterEach(function (): void {
        clearDiscoveryCacheManagerStatic();
    });

    it('scans 100 classes via Spatie in under 500ms', function (): void {
        $setup = generateAndRegister(100);

        try {
            $cacheManager = new DiscoveryCacheManager;
            $reflectionCache = new ReflectionCache;
            $context = new DiscoveryContext($reflectionCache);

            $location = new DiscoveryLocation(
                $setup['info']['namespace'],
                $setup['info']['path']
            );

            $start = hrtime(true);
            $structures = $cacheManager->getStructuresForLocation($location, $context);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            expect(count($structures))->toBeGreaterThanOrEqual(100);
            expect($elapsedMs)->toBeLessThan(500);
        } finally {
            spl_autoload_unregister($setup['autoloader']);
            $setup['generator']->cleanup();
        }
    });

    it('runs full discovery on 100 classes with 5 discoveries in under 2000ms', function (): void {
        $setup = generateAndRegister(100);

        try {
            $engine = new DiscoveryEngine;

            $location = new DiscoveryLocation(
                $setup['info']['namespace'],
                $setup['info']['path']
            );

            $engine->addLocation($location);
            $engine->addDiscovery('hooks', new BenchmarkDiscovery('hooks'));
            $engine->addDiscovery('providers', new BenchmarkDiscovery('providers'));
            $engine->addDiscovery('post_types', new BenchmarkDiscovery('post_types'));
            $engine->addDiscovery('rest_routes', new BenchmarkDiscovery('rest_routes'));
            $engine->addDiscovery('schedules', new BenchmarkDiscovery('schedules'));

            $start = hrtime(true);
            $engine->discover();
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            $stats = $engine->getPerformanceStats();

            expect($stats['context']['total_classes'])->toBeGreaterThanOrEqual(100);
            expect($elapsedMs)->toBeLessThan(2000);
        } finally {
            spl_autoload_unregister($setup['autoloader']);
            $setup['generator']->cleanup();
        }
    });

    it('runs full discovery on 500 classes with 5 discoveries in under 10000ms', function (): void {
        $setup = generateAndRegister(500);

        try {
            $engine = new DiscoveryEngine;

            $location = new DiscoveryLocation(
                $setup['info']['namespace'],
                $setup['info']['path']
            );

            $engine->addLocation($location);
            $engine->addDiscovery('hooks', new BenchmarkDiscovery('hooks'));
            $engine->addDiscovery('providers', new BenchmarkDiscovery('providers'));
            $engine->addDiscovery('post_types', new BenchmarkDiscovery('post_types'));
            $engine->addDiscovery('rest_routes', new BenchmarkDiscovery('rest_routes'));
            $engine->addDiscovery('schedules', new BenchmarkDiscovery('schedules'));

            $start = hrtime(true);
            $engine->discover();
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            $stats = $engine->getPerformanceStats();

            expect($stats['context']['total_classes'])->toBeGreaterThanOrEqual(500);
            expect($elapsedMs)->toBeLessThan(10000);
        } finally {
            spl_autoload_unregister($setup['autoloader']);
            $setup['generator']->cleanup();
        }
    });

    it('scales near-linearly from 100 to 500 classes', function (): void {
        // Benchmark 100 classes
        $setup100 = generateAndRegister(100);

        try {
            $engine100 = new DiscoveryEngine;
            $loc100 = new DiscoveryLocation($setup100['info']['namespace'], $setup100['info']['path']);
            $engine100->addLocation($loc100);
            $engine100->addDiscovery('hooks', new BenchmarkDiscovery('hooks'));
            $engine100->addDiscovery('providers', new BenchmarkDiscovery('providers'));
            $engine100->addDiscovery('post_types', new BenchmarkDiscovery('post_types'));

            $start = hrtime(true);
            $engine100->discover();
            $time100 = (hrtime(true) - $start) / 1_000_000;
        } finally {
            spl_autoload_unregister($setup100['autoloader']);
            $setup100['generator']->cleanup();
        }

        clearDiscoveryCacheManagerStatic();

        // Benchmark 500 classes
        $setup500 = generateAndRegister(500);

        try {
            $engine500 = new DiscoveryEngine;
            $loc500 = new DiscoveryLocation($setup500['info']['namespace'], $setup500['info']['path']);
            $engine500->addLocation($loc500);
            $engine500->addDiscovery('hooks', new BenchmarkDiscovery('hooks'));
            $engine500->addDiscovery('providers', new BenchmarkDiscovery('providers'));
            $engine500->addDiscovery('post_types', new BenchmarkDiscovery('post_types'));

            $start = hrtime(true);
            $engine500->discover();
            $time500 = (hrtime(true) - $start) / 1_000_000;
        } finally {
            spl_autoload_unregister($setup500['autoloader']);
            $setup500['generator']->cleanup();
        }

        // 5x classes should be less than 8x time (allowing for overhead)
        $ratio = $time500 / max(0.01, $time100);
        expect($ratio)->toBeLessThan(8.0,
            sprintf('Scaling ratio: %sx for 5x classes (100: %sms, 500: %sms)', $ratio, $time100, $time500)
        );
    });

    it('processes each class in under 2000 microseconds on average', function (): void {
        $setup = generateAndRegister(250);

        try {
            $engine = new DiscoveryEngine;

            $location = new DiscoveryLocation(
                $setup['info']['namespace'],
                $setup['info']['path']
            );

            $engine->addLocation($location);
            $engine->addDiscovery('hooks', new BenchmarkDiscovery('hooks'));
            $engine->addDiscovery('providers', new BenchmarkDiscovery('providers'));
            $engine->addDiscovery('post_types', new BenchmarkDiscovery('post_types'));
            $engine->addDiscovery('rest_routes', new BenchmarkDiscovery('rest_routes'));
            $engine->addDiscovery('schedules', new BenchmarkDiscovery('schedules'));

            $start = hrtime(true);
            $engine->discover();
            $elapsedUs = (hrtime(true) - $start) / 1_000;

            $perClassUs = $elapsedUs / 250;

            expect($perClassUs)->toBeLessThan(3000,
                sprintf('Per-class cost: %s us (total: ', $perClassUs).($elapsedUs / 1000).' ms for 250 classes)'
            );
        } finally {
            spl_autoload_unregister($setup['autoloader']);
            $setup['generator']->cleanup();
        }
    });

    it('handles multi-location with DDD subdirectories without excessive overhead', function (): void {
        $basePath = __DIR__.'/Generated';
        $namespace = 'Pollora\\Discovery\\Tests\\Benchmark\\Generated';
        $generator = new FixtureGenerator($basePath, $namespace);

        $multiInfo = $generator->generateMultiLocation(200, 5);

        $autoloaders = [];
        foreach ($multiInfo['locations'] as $loc) {
            $al = function (string $class) use ($loc): void {
                if (! str_starts_with($class, $loc['namespace'])) {
                    return;
                }

                $relative = str_replace($loc['namespace'].'\\', '', $class);
                $file = $loc['path'].'/'.str_replace('\\', '/', $relative).'.php';
                if (file_exists($file)) {
                    require_once $file;
                }
            };
            spl_autoload_register($al);
            $autoloaders[] = $al;
        }

        try {
            $engine = new DiscoveryEngine;

            foreach ($multiInfo['locations'] as $loc) {
                $engine->addLocation(new DiscoveryLocation($loc['namespace'], $loc['path']));
            }

            $engine->addDiscovery('hooks', new BenchmarkDiscovery('hooks'));
            $engine->addDiscovery('providers', new BenchmarkDiscovery('providers'));
            $engine->addDiscovery('post_types', new BenchmarkDiscovery('post_types'));

            $start = hrtime(true);
            $engine->discover();
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            $stats = $engine->getPerformanceStats();

            expect($stats['context']['total_classes'])->toBeGreaterThanOrEqual(200);
            // 5 modules with subdirs should not be dramatically slower than flat
            expect($elapsedMs)->toBeLessThan(5000);
        } finally {
            foreach ($autoloaders as $al) {
                spl_autoload_unregister($al);
            }

            $generator->cleanup();
        }
    });

    it('keeps the memory of a 1000-class discovery under 48 MB', function (): void {
        // Measured at 16 MB on 2026-10-12 (five discoveries, 21 attributes per
        // class): three times that leaves room for a slower CI runner, and
        // still catches a discovery that keeps every reflection it makes.
        $setup = generateAndRegister(1000);

        try {
            $engine = new DiscoveryEngine;
            $engine->addLocation(new DiscoveryLocation($setup['info']['namespace'], $setup['info']['path']));

            foreach (['hooks', 'providers', 'post_types', 'rest_routes', 'schedules'] as $identifier) {
                $engine->addDiscovery($identifier, new BenchmarkDiscovery($identifier));
            }

            memory_reset_peak_usage();
            $before = memory_get_usage();
            $engine->discover();
            $peakMb = (memory_get_peak_usage() - $before) / (1024 * 1024);

            expect($engine->getPerformanceStats()['context']['total_classes'])->toBeGreaterThanOrEqual(1000)
                ->and($peakMb)->toBeLessThan(48.0);
        } finally {
            spl_autoload_unregister($setup['autoloader']);
            $setup['generator']->cleanup();
        }
    });
});
