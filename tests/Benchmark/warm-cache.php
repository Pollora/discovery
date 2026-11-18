<?php

declare(strict_types=1);

/**
 * PERF-02 micro-benchmark (outside the test suite): cold vs warm discovery.
 *
 * Usage: php tests/Benchmark/warm-cache.php [classes=200] [runs=15]
 *
 * Timings are informative only. The CI guarantee is the counter-based
 * tests/Unit/Infrastructure/Services/WarmCacheReadsTest.php.
 */

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../Support/FileAccessProbe.php';
require_once __DIR__.'/../Support/WarmCacheFixtures.php';

use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\DiscoveryCacheManager;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\Discovery\Tests\Support\FileAccessProbe;
use Pollora\Discovery\Tests\Support\WarmCacheFixtures;
use Spatie\StructureDiscoverer\Cache\FileDiscoverCacheDriver;
use Spatie\StructureDiscoverer\Discover;

$classes = (int) ($argv[1] ?? 200);
$runs = (int) ($argv[2] ?? 15);

$root = sys_get_temp_dir().'/pollora-warm-bench-'.bin2hex(random_bytes(4));
$src = WarmCacheFixtures::classes($root.'/src', $classes);
$driver = new FileDiscoverCacheDriver($root.'/cache');
$memo = new ReflectionProperty(DiscoveryCacheManager::class, 'structuresCache');

$engine = static function () use ($driver, $src): DiscoveryEngine {
    $memo = new ReflectionProperty(DiscoveryCacheManager::class, 'structuresCache');
    $memo->setValue(null, []);

    return (new DiscoveryEngine(cacheManager: new DiscoveryCacheManager($driver)))
        ->addLocation(new DiscoveryLocation('WarmFixtures', $src));
};

$median = static function (array $values): float {
    sort($values);

    return $values[intdiv(count($values), 2)];
};

// Timed without the probe (its stream wrapper slows every file call); counted separately.
$time = static function (callable $fn) use ($src): array {
    $start = hrtime(true);
    $fn();
    $ms = (hrtime(true) - $start) / 1e6;

    return [$ms, FileAccessProbe::during($src, $fn)];
};

$cold = [];
$warm = [];
$old = [];
$new = [];

for ($i = 0; $i < $runs; $i++) {
    $driver->forget('discovery_'.md5(realpath($src)));
    [$ms, $coldCounts] = $time(function () use ($engine, $driver, $src): void {
        $driver->forget('discovery_'.md5(realpath($src)));
        $engine()->discover();
    });
    $cold[] = $ms;

    [$ms, $warmCounts] = $time(fn () => $engine()->discover());
    $warm[] = $ms;

    // The pre-fix warm path: manager's has(), then Spatie's has() + get().
    $id = 'discovery_'.md5(realpath($src));
    $start = hrtime(true);
    $driver->has($id);
    Discover::in($src)->full()->withCache($id, $driver)->get();
    $old[] = (hrtime(true) - $start) / 1e6;
    $start = hrtime(true);
    $driver->has($id);
    $driver->get($id);
    $new[] = (hrtime(true) - $start) / 1e6;
}

printf("%d classes, %d runs, file cache (serialized), medians\n", $classes, $runs);
printf("cold  (scan + write)      : %8.2f ms  opens=%d dirs=%d stats=%d\n", $median($cold), $coldCounts['opens'], $coldCounts['dirs'], $coldCounts['stats']);
printf("warm  (cache read)        : %8.2f ms  opens=%d dirs=%d stats=%d\n", $median($warm), $warmCounts['opens'], $warmCounts['dirs'], $warmCounts['stats']);
printf("cache reads only, before : %8.3f ms  (has + Spatie has + get)\n", $median($old));
printf("cache reads only, after  : %8.3f ms  (has + get)\n", $median($new));
printf("cold / warm               : %8.1fx\n", $median($cold) / max($median($warm), 0.001));

WarmCacheFixtures::remove($root);
