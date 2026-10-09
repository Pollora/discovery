<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Services;

use Illuminate\Container\Container;
use Pollora\Application\Domain\Contracts\DebugDetectorInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Models\DiscoveryContext;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Cache\DiscoverCacheDriver;
use Spatie\StructureDiscoverer\Cache\LaravelDiscoverCacheDriver;
use Spatie\StructureDiscoverer\Cache\NullDiscoverCacheDriver;
use Spatie\StructureDiscoverer\Discover;

/**
 * Manages caching for the discovery engine.
 *
 * Handles Spatie's structure discoverer cache configuration,
 * in-memory static caching of discovered structures, and
 * cache driver resolution from Laravel configuration.
 */
class DiscoveryCacheManager
{
    /**
     * Static cache for discovered structures to avoid repeated scans.
     *
     * @var array<string, mixed>
     */
    private static array $structuresCache = [];

    /**
     * Above this, one location's scan is reported rather than absorbed.
     */
    private const int SLOW_SCAN_MS = 250;

    /**
     * Resolved cache driver for Spatie's structure discovery.
     */
    private readonly ?DiscoverCacheDriver $cacheDriver;

    /**
     * Where each location's structures came from in this process, in order.
     *
     * @var list<array{path: string, source: 'memory'|'cache'|'disk', milliseconds: float, structures: int}>
     */
    private array $scans = [];

    public function __construct(
        private readonly Container $container,
        private readonly DebugDetectorInterface $debugDetector
    ) {
        $this->cacheDriver = $this->resolveCacheDriver();
    }

    /**
     * Get structures for a specific location, using static and Spatie cache.
     *
     * @param  DiscoveryLocationInterface  $location  The discovery location
     * @param  DiscoveryContext  $context  Context for recording cache stats
     * @return array<mixed> The discovered structures
     */
    public function getStructuresForLocation(DiscoveryLocationInterface $location, DiscoveryContext $context): array
    {
        $cacheId = $this->generateCacheId($location);

        if (isset(self::$structuresCache[$cacheId])) {
            $context->recordCacheHit();
            $this->recordScan($location, 'memory', 0.0, count(self::$structuresCache[$cacheId]));

            return self::$structuresCache[$cacheId];
        }

        $context->recordCacheMiss();

        // Asked before reading: once the discoverer has run, a cold cache has
        // been filled and looks exactly like a warm one.
        $fromCache = $this->shouldUseCache() && $this->cacheDriver->has($cacheId);

        $discover = $this->createSpatieDiscoverer($location, $cacheId);

        $startedAt = microtime(true);
        $structures = $discover->get();
        $elapsed = (microtime(true) - $startedAt) * 1000;

        self::$structuresCache[$cacheId] = $structures;
        $this->recordScan($location, $fromCache ? 'cache' : 'disk', $elapsed, count($structures));

        $this->warnIfSlow($location, $elapsed, count($structures));

        return $structures;
    }

    /**
     * Where each location's structures came from, and what it cost.
     *
     * `memory` is this process's own copy, `cache` the persistent cache, `disk`
     * a real scan — the one that costs, and the only one in debug mode, where
     * the persistent cache is off. The context's hit and miss counters only
     * know about the first of the three.
     *
     * @return list<array{path: string, source: 'memory'|'cache'|'disk', milliseconds: float, structures: int}>
     */
    public function scans(): array
    {
        return $this->scans;
    }

    /**
     * @param  'memory'|'cache'|'disk'  $source
     */
    private function recordScan(DiscoveryLocationInterface $location, string $source, float $milliseconds, int $structures): void
    {
        $this->scans[] = [
            'path' => $location->getPath(),
            'source' => $source,
            'milliseconds' => round($milliseconds, 2),
            'structures' => $structures,
        ];
    }

    /**
     * Say so when one location costs more than it can possibly be worth.
     *
     * Discovery walks a directory in full, and Symfony's Finder enumerates
     * before it filters by extension — so a `node_modules/` anywhere under a
     * location is paid for on every request that misses the cache, which in
     * debug mode is every request. Measured once: 69,741 files walked to reach
     * three PHP files, 1,691 ms against 1 ms, and nothing anywhere said so.
     *
     * The threshold is deliberately far above a healthy scan — the same site's
     * other four locations came in between 2 and 25 ms — so this stays quiet
     * until something is actually wrong. Debug only: the cache carries the
     * cost in production, and a log line per request is its own problem.
     */
    private function warnIfSlow(DiscoveryLocationInterface $location, float $elapsed, int $structures): void
    {
        if ($elapsed < self::SLOW_SCAN_MS || ! $this->debugDetector->isDebugMode()) {
            return;
        }

        try {
            $this->container->make(LoggerInterface::class)->warning(sprintf(
                'Pollora discovery scanned %s in %dms for %d structure(s). '
                .'A directory this slow usually holds node_modules, vendor or a build output; '
                .'discovery walks every file in it on each request while the cache is off.',
                $location->getPath(),
                (int) round($elapsed),
                $structures
            ));
        } catch (\Throwable) {
            // A missing logger must not turn a warning into a failure.
        }
    }

    /**
     * Clear persistent Spatie cache for the given locations.
     *
     * @param  iterable<DiscoveryLocationInterface>  $locations
     */
    public function clearCache(iterable $locations): void
    {
        if (! $this->cacheDriver instanceof DiscoverCacheDriver) {
            return;
        }

        foreach ($locations as $location) {
            $cacheId = $this->generateCacheId($location);
            $this->cacheDriver->forget($cacheId);
        }
    }

    /**
     * Classes on disk that the cached entry for a location does not list.
     *
     * They stay invisible to discovery — no hook, no post type, no route — until
     * the cache is cleared, and nothing says so. Null when the location has no
     * cached entry, or the cache is off, since then discovery reads the disk.
     *
     * @return list<string>|null
     */
    public function classesMissingFromCache(DiscoveryLocationInterface $location): ?array
    {
        if (! $this->shouldUseCache()) {
            return null;
        }

        $cacheId = $this->generateCacheId($location);

        if (! $this->cacheDriver->has($cacheId)) {
            return null;
        }

        $names = static fn (array $structures): array => array_map(
            static fn (mixed $structure): string => is_object($structure) && method_exists($structure, 'getFcqn') ? $structure->getFcqn() : (string) $structure,
            $structures,
        );

        return array_values(array_diff(
            $names(Discover::in($location->getPath())->full()->get()),
            $names($this->cacheDriver->get($cacheId)),
        ));
    }

    /**
     * Whether discovery reads through a persistent cache (it does not in debug mode).
     */
    public function isCacheEnabled(): bool
    {
        return $this->shouldUseCache();
    }

    /**
     * Get the cache driver instance.
     */
    public function getCacheDriver(): ?DiscoverCacheDriver
    {
        return $this->cacheDriver;
    }

    /**
     * Get the number of statically cached entries.
     */
    public function getStaticCacheSize(): int
    {
        return count(self::$structuresCache);
    }

    /**
     * Resolve the cache driver from Laravel configuration.
     */
    private function resolveCacheDriver(): ?DiscoverCacheDriver
    {
        if ($this->debugDetector->isDebugMode()) {
            return new NullDiscoverCacheDriver;
        }

        $cacheConfig = config('structure-discoverer.cache', []);
        $driverClass = $cacheConfig['driver'] ?? LaravelDiscoverCacheDriver::class;
        $store = $cacheConfig['store'] ?? null;

        if ($driverClass === LaravelDiscoverCacheDriver::class) {
            return new LaravelDiscoverCacheDriver(prefix: 'pollora', store: $store);
        }

        try {
            return $this->container->make($driverClass);
        } catch (\Throwable) {
            return new LaravelDiscoverCacheDriver(prefix: 'pollora', store: $store);
        }
    }

    /**
     * Create a configured Spatie discoverer instance.
     */
    private function createSpatieDiscoverer(DiscoveryLocationInterface $location, string $cacheId): Discover
    {
        $discover = Discover::in($location->getPath())->full();

        if ($this->shouldUseCache()) {
            return $discover->withCache($cacheId, $this->cacheDriver);
        }

        return $discover;
    }

    /**
     * Determine if caching should be used.
     */
    private function shouldUseCache(): bool
    {
        return $this->cacheDriver instanceof DiscoverCacheDriver
            && ! ($this->cacheDriver instanceof NullDiscoverCacheDriver);
    }

    /**
     * Generate cache ID for a discovery location.
     */
    private function generateCacheId(DiscoveryLocationInterface $location): string
    {
        return 'discovery_'.md5($location->getPath());
    }
}
