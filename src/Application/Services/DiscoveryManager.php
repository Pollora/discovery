<?php

declare(strict_types=1);

namespace Pollora\Discovery\Application\Services;

use Illuminate\Support\Collection;
use Pollora\Discovery\Domain\Contracts\DiscoveryEngineInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;

/**
 * Discovery Manager
 *
 * High-level service that provides a clean API for managing the discovery system.
 * This manager acts as a facade over the discovery engine, providing convenient
 * methods for common discovery operations.
 *
 * Inspired by Tempest Framework's API while leveraging Spatie's structure discovery.
 */
class DiscoveryManager
{
    /**
     * Create a new discovery manager
     *
     * @param  DiscoveryEngineInterface  $engine  The discovery engine
     */
    public function __construct(
        private readonly DiscoveryEngineInterface $engine
    ) {}

    /**
     * Add a discovery location by path and namespace
     *
     * @param  string  $namespace  The base namespace for the location
     * @param  string  $path  The filesystem path to scan
     * @return static Returns self for method chaining
     */
    public function addLocation(string $namespace, string $path): static
    {
        $location = new DiscoveryLocation($namespace, $path);
        $this->engine->addLocation($location);

        return $this;
    }

    /**
     * Add multiple discovery locations
     *
     * @param  array<array{namespace: string, path: string}>  $locations  Array of location data
     * @return static Returns self for method chaining
     */
    public function addLocations(array $locations): static
    {
        foreach ($locations as $locationData) {
            $this->addLocation($locationData['namespace'], $locationData['path']);
        }

        return $this;
    }

    /**
     * Register a discovery class
     *
     * @param  string  $identifier  Unique identifier for the discovery
     * @param  string|DiscoveryInterface  $discovery  Discovery class name or instance
     * @return static Returns self for method chaining
     */
    public function addDiscovery(string $identifier, string|DiscoveryInterface $discovery): static
    {
        $this->engine->addDiscovery($identifier, $discovery);

        return $this;
    }

    /**
     * Register multiple discovery classes
     *
     * @param  array<string, string|DiscoveryInterface>  $discoveries  Map of identifier to discovery
     * @return static Returns self for method chaining
     */
    public function addDiscoveries(array $discoveries): static
    {
        $this->engine->addDiscoveries($discoveries);

        return $this;
    }

    /**
     * Execute discovery and apply all discoveries
     *
     * This is the main method that runs the entire discovery process.
     *
     * @return static Returns self for method chaining
     */
    public function run(): static
    {
        $this->engine->run();

        return $this;
    }

    /**
     * Execute only the discovery phase
     *
     * Useful for testing or when you want to inspect discovered items
     * before applying them.
     *
     * @return static Returns self for method chaining
     */
    public function discover(): static
    {
        $this->engine->discover();

        return $this;
    }

    /**
     * Execute only the application phase
     *
     * Applies previously discovered items. Should be called after discover().
     *
     * @return static Returns self for method chaining
     */
    public function apply(): static
    {
        $this->engine->apply();

        return $this;
    }

    /**
     * Get a specific discovery by identifier
     *
     * @param  string  $identifier  The discovery identifier
     * @return DiscoveryInterface The discovery instance
     */
    public function getDiscovery(string $identifier): DiscoveryInterface
    {
        return $this->engine->getDiscovery($identifier);
    }

    /**
     * Get all registered discoveries
     *
     * @return Collection<string, DiscoveryInterface> Collection of discoveries keyed by identifier
     */
    public function getDiscoveries(): Collection
    {
        return $this->engine->getDiscoveries();
    }

    /**
     * Get all discovery locations
     *
     * @return Collection<int, DiscoveryLocationInterface> Collection of discovery locations
     */
    public function getLocations(): Collection
    {
        return $this->engine->getLocations();
    }

    /**
     * Clear persistent discovery cache
     * 
     * Only clears persistent cache (Spatie structure discoverer cache).
     * In-memory caches are automatically cleared at process end.
     *
     * @return static Returns self for method chaining
     */
    public function clearCache(): static
    {
        $this->engine->clearCache();

        return $this;
    }

    /**
     * Check if a discovery is registered
     *
     * @param  string  $identifier  The discovery identifier to check
     * @return bool True if the discovery is registered, false otherwise
     */
    public function hasDiscovery(string $identifier): bool
    {
        try {
            $this->getDiscovery($identifier);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get discovered items for a specific discovery
     *
     * @param  string  $identifier  The discovery identifier
     * @return array<mixed> The discovered items
     */
    public function getDiscoveredItems(string $identifier): array
    {
        $discovery = $this->getDiscovery($identifier);

        return $discovery->getItems()->all();
    }

    /**
     * Run a specific discovery type on a specific location
     *
     * @param  string  $identifier  The discovery identifier
     * @param  DiscoveryLocationInterface  $location  The location to discover in
     * @return static Returns self for method chaining
     */
    public function runSpecificDiscovery(string $identifier, DiscoveryLocationInterface $location): static
    {
        if (! $this->hasDiscovery($identifier)) {
            return $this;
        }

        // Create a temporary engine with just this discovery and location
        $tempEngine = clone $this->engine;
        $tempEngine->clearLocations();
        $tempEngine->addLocation($location);

        // Run only this specific discovery
        $discovery = $this->getDiscovery($identifier);
        $tempEngine->runDiscovery($identifier, $discovery);

        return $this;
    }

    /**
     * Discover all structure types in a location
     *
     * @param  DiscoveryLocationInterface  $location  The location to discover in
     * @return array<string, array> Results grouped by discovery type
     */
    public function discoverAllInLocation(DiscoveryLocationInterface $location): array
    {
        $results = [];

        // Create a temporary engine for this location
        $tempEngine = clone $this->engine;
        $tempEngine->clearLocations();
        $tempEngine->addLocation($location);

        // Run discovery and collect results by type
        $tempEngine->discover();

        foreach ($this->getDiscoveries() as $identifier => $discovery) {
            $items = $discovery->getItems()->all();
            if (! empty($items)) {
                $results[$identifier] = $items;
            }
        }

        return $results;
    }

    /**
     * Get the underlying discovery engine
     *
     * @return DiscoveryEngineInterface The discovery engine instance
     */
    public function getEngine(): DiscoveryEngineInterface
    {
        return $this->engine;
    }

    /**
     * Get performance statistics from the optimized engine
     *
     * @return array<string, mixed> Performance statistics or empty array if not available
     */
    public function getPerformanceStats(): array
    {
        if (method_exists($this->engine, 'getPerformanceStats')) {
            return $this->engine->getPerformanceStats();
        }

        return [];
    }

    /**
     * Get debugging information about the discovery state
     *
     * @return array<string, mixed> Debug information
     */
    public function getDebugInfo(): array
    {
        $debugInfo = [
            'discoveries_count' => $this->getDiscoveries()->count(),
            'locations_count' => $this->getLocations()->count(),
            'discoveries' => [],
            'locations' => [],
        ];

        // Add discovery details
        foreach ($this->getDiscoveries() as $identifier => $discovery) {
            $items = $this->getDiscoveredItems($identifier);
            $debugInfo['discoveries'][$identifier] = [
                'class' => get_class($discovery),
                'items_count' => count($items),
                'items' => array_slice($items, 0, 3), // Show first 3 items for debugging
            ];
        }

        // Add location details
        foreach ($this->getLocations() as $location) {
            $debugInfo['locations'][] = [
                'path' => $location->getPath(),
                'namespace' => $location->getNamespace(),
                'exists' => is_dir($location->getPath()),
            ];
        }

        return $debugInfo;
    }
}
