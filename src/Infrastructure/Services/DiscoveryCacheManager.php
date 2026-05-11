<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Services;

use Illuminate\Container\Container;
use Pollora\Application\Domain\Contracts\DebugDetectorInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Models\DiscoveryContext;
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
     * Resolved cache driver for Spatie's structure discovery.
     */
    private readonly ?DiscoverCacheDriver $cacheDriver;

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

            return self::$structuresCache[$cacheId];
        }

        $context->recordCacheMiss();

        $discover = $this->createSpatieDiscoverer($location, $cacheId);
        $structures = $discover->get();

        self::$structuresCache[$cacheId] = $structures;

        return $structures;
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
