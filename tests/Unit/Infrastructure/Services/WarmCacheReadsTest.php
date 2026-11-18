<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryItemsInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Models\DiscoveryItems;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Cache\Psr16DiscoverCacheDriver;
use Pollora\Discovery\Infrastructure\Services\DiscoveryCacheManager;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\Discovery\Tests\Support\FileAccessProbe;
use Pollora\Discovery\Tests\Support\WarmCacheFixtures;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

require_once __DIR__.'/../../../Support/FileAccessProbe.php';
require_once __DIR__.'/../../../Support/WarmCacheFixtures.php';

/*
 * PERF-02: discovery reads no file when its cache is warm.
 *
 * The assertions are counters, never durations: FileAccessProbe counts the
 * opens, stats and directory reads PHP makes under the scanned directory, and
 * a counting PSR-16 cache counts the reads the cache itself costs. Each warm
 * claim is paired with a witness (cold cache, cleared cache) where the same
 * probe must see the reads, so a probe that stops seeing anything fails.
 */

const WARM_CLASS_COUNT = 24;

/**
 * A discovery that only looks at the discovered structure, never at the class
 * itself: reflecting would autoload, and loading a class reads its file, which
 * is the host's autoloader cost and not the discovery's.
 */
function structureOnlyDiscovery(): DiscoveryInterface
{
    return new class implements DiscoveryInterface
    {
        public int $seen = 0;

        private DiscoveryItemsInterface $items;

        public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
        {
            if ($structure instanceof DiscoveredClass) {
                $this->seen++;
            }
        }

        public function getIdentifier(): string
        {
            return 'probe';
        }

        public function apply(): void {}

        public function getItems(): DiscoveryItemsInterface
        {
            return $this->items ??= new DiscoveryItems;
        }

        public function setItems(DiscoveryItemsInterface $items): void
        {
            $this->items = $items;
        }
    };
}

/** @return array{0: DiscoveryEngine, 1: DiscoveryInterface} a fresh engine (empty in-process cache) over the shared cache. */
function warmEngine(Psr16DiscoverCacheDriver $driver, string $directory): array
{
    // A new request: the in-process memo is gone, only the PSR-16 cache remains.
    (new ReflectionProperty(DiscoveryCacheManager::class, 'structuresCache'))->setValue(null, []);

    $discovery = structureOnlyDiscovery();
    $engine = (new DiscoveryEngine(cacheManager: new DiscoveryCacheManager($driver)))
        ->addLocation(new DiscoveryLocation('WarmFixtures', $directory))
        ->addDiscovery('probe', $discovery);

    return [$engine, $discovery];
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/pollora-warm-'.bin2hex(random_bytes(6));
    WarmCacheFixtures::classes($this->dir, WARM_CLASS_COUNT);
    $this->cache = WarmCacheFixtures::countingCache();
    $this->driver = new Psr16DiscoverCacheDriver($this->cache);
});

afterEach(function (): void {
    WarmCacheFixtures::remove($this->dir);
});

describe('file access probe', function (): void {
    it('sees opens, stats and directory reads under the watched directory only', function (): void {
        $outside = tempnam(sys_get_temp_dir(), 'outside');

        $counts = FileAccessProbe::during($this->dir, function () use ($outside): void {
            file_get_contents($this->dir.'/Item0000.php');
            is_file($this->dir.'/Item0001.php');
            scandir($this->dir);
            file_get_contents($outside);
        });

        unlink($outside);

        expect($counts['opens'])->toBe(1)
            ->and($counts['stats'])->toBeGreaterThanOrEqual(1)
            ->and($counts['dirs'])->toBe(1)
            ->and($counts['paths'])->not->toContain($outside);
    });

    it('restores the native file wrapper when it stops', function (): void {
        FileAccessProbe::during($this->dir, static fn () => null);

        expect(file_get_contents($this->dir.'/Item0000.php'))->toContain('class Item0000');
    });
});

describe('cold cache', function (): void {
    it('reads every source file and fills the cache', function (): void {
        [$engine, $discovery] = warmEngine($this->driver, $this->dir);

        $counts = FileAccessProbe::during($this->dir, fn () => $engine->discover());

        expect($discovery->seen)->toBe(WARM_CLASS_COUNT)
            ->and($counts['opens'])->toBeGreaterThanOrEqual(WARM_CLASS_COUNT)
            ->and($counts['dirs'])->toBeGreaterThanOrEqual(1)
            ->and($this->cache->calls['set'])->toBe(1);
    });
});

describe('warm cache', function (): void {
    beforeEach(function (): void {
        [$engine] = warmEngine($this->driver, $this->dir);
        $engine->discover();
        $this->cache->calls = array_map(static fn (): int => 0, $this->cache->calls);
    });

    it('opens, stats and lists nothing in the scanned directory', function (): void {
        [$engine, $discovery] = warmEngine($this->driver, $this->dir);

        $counts = FileAccessProbe::during($this->dir, fn () => $engine->discover());

        expect($discovery->seen)->toBe(WARM_CLASS_COUNT)
            ->and($counts)->toMatchArray(['opens' => 0, 'stats' => 0, 'dirs' => 0, 'total' => 0, 'paths' => []]);
    });

    it('does not scan again, whatever the number of runs', function (): void {
        $counts = FileAccessProbe::during($this->dir, function (): void {
            for ($run = 0; $run < 3; $run++) {
                warmEngine($this->driver, $this->dir)[0]->discover();
            }
        });

        expect($counts['total'])->toBe(0)
            ->and($this->cache->calls['set'])->toBe(0);
    });

    it('reads the cached entry once per location, not once per question', function (): void {
        warmEngine($this->driver, $this->dir)[0]->discover();

        // has() on a file store unserializes the whole entry: it must not be paid twice or more.
        expect($this->cache->calls['get'])->toBe(1)
            ->and($this->cache->calls['has'])->toBeLessThanOrEqual(1);
    });

    it('lets a cleared cache scan again, which proves the probe is not blind', function (): void {
        [$engine, $discovery] = warmEngine($this->driver, $this->dir);
        $engine->clearCache();

        $counts = FileAccessProbe::during($this->dir, fn () => $engine->discover());

        expect($discovery->seen)->toBe(WARM_CLASS_COUNT)
            ->and($counts['opens'])->toBeGreaterThanOrEqual(WARM_CLASS_COUNT)
            ->and($counts['dirs'])->toBeGreaterThanOrEqual(1);
    });

    it('answers from memory within a process without touching the cache or the disk', function (): void {
        [$engine] = warmEngine($this->driver, $this->dir);
        $engine->discover();
        $this->cache->calls = array_map(static fn (): int => 0, $this->cache->calls);

        $counts = FileAccessProbe::during($this->dir, fn () => $engine->discover());

        expect($counts['total'])->toBe(0)
            ->and(array_sum($this->cache->calls))->toBe(0);
    });
});

describe('invalidation', function (): void {
    it('keeps serving the cached structures after a file changes, until the cache is cleared', function (): void {
        [$engine] = warmEngine($this->driver, $this->dir);
        $engine->discover();

        // The warm path trusts the cache and reads nothing: a new class is seen after `discovery:clear`, not before.
        WarmCacheFixtures::classes($this->dir, WARM_CLASS_COUNT + 1);

        [$stale, $staleDiscovery] = warmEngine($this->driver, $this->dir);
        $stale->discover();

        [$fresh, $freshDiscovery] = warmEngine($this->driver, $this->dir);
        $fresh->clearCache()->discover();

        expect($staleDiscovery->seen)->toBe(WARM_CLASS_COUNT)
            ->and($freshDiscovery->seen)->toBe(WARM_CLASS_COUNT + 1);
    });

    it('labels a scan by where it came from', function (): void {
        [$cold] = warmEngine($this->driver, $this->dir);
        $cold->discover();
        [$warm] = warmEngine($this->driver, $this->dir);
        $warm->discover();

        expect($cold->getCacheManager()->scans()[0]['source'])->toBe('disk')
            ->and($warm->getCacheManager()->scans()[0]['source'])->toBe('cache');
    });
});

describe('without a cache', function (): void {
    it('scans on every run', function (): void {
        $run = function (): array {
            (new ReflectionProperty(DiscoveryCacheManager::class, 'structuresCache'))->setValue(null, []);
            $engine = (new DiscoveryEngine(cacheManager: new DiscoveryCacheManager))
                ->addLocation(new DiscoveryLocation('WarmFixtures', $this->dir))
                ->addDiscovery('probe', structureOnlyDiscovery());

            return FileAccessProbe::during($this->dir, fn () => $engine->discover());
        };

        expect($run()['opens'])->toBeGreaterThanOrEqual(WARM_CLASS_COUNT)
            ->and($run()['opens'])->toBeGreaterThanOrEqual(WARM_CLASS_COUNT);
    });
});
