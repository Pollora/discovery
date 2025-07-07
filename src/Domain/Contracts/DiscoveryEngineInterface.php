<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Contracts;

use Illuminate\Support\Collection;

/**
 * Discovery Engine Interface
 *
 * Defines the contract for the discovery engine that orchestrates
 * the discovery process across multiple locations and discovery classes.
 *
 * The engine is responsible for:
 * - Managing discovery locations
 * - Coordinating discovery classes
 * - Handling caching strategies
 * - Executing the discovery and application process
 */
interface DiscoveryEngineInterface
{
    /**
     * Add a discovery location
     *
     * @param  DiscoveryLocationInterface  $location  The location to add for discovery
     * @return static Returns self for method chaining
     */
    public function addLocation(DiscoveryLocationInterface $location): static;

    /**
     * Add multiple discovery locations
     *
     * @param  array<DiscoveryLocationInterface>  $locations  The locations to add
     * @return static Returns self for method chaining
     */
    public function addLocations(array $locations): static;

    /**
     * Register a discovery class
     *
     * @param  string  $identifier  Unique identifier for the discovery
     * @param  string|DiscoveryInterface  $discovery  Discovery class name or instance
     * @return static Returns self for method chaining
     */
    public function addDiscovery(string $identifier, string|DiscoveryInterface $discovery): static;

    /**
     * Register multiple discovery classes
     *
     * @param  array<string, string|DiscoveryInterface>  $discoveries  Map of identifier to discovery
     * @return static Returns self for method chaining
     */
    public function addDiscoveries(array $discoveries): static;

    /**
     * Execute discovery process for all registered discoveries
     *
     * Runs the discovery phase for all registered discovery classes
     * across all configured locations.
     *
     * @return static Returns self for method chaining
     *
     * @throws \Pollora\Discovery\Domain\Exceptions\DiscoveryException When discovery fails
     */
    public function discover(): static;

    /**
     * Apply all discoveries
     *
     * Applies all discovered items by calling the apply() method
     * on all registered discovery classes.
     *
     * @return static Returns self for method chaining
     *
     * @throws \Pollora\Discovery\Domain\Exceptions\DiscoveryException When application fails
     */
    public function apply(): static;

    /**
     * Execute discovery and apply in one operation
     *
     * Convenience method that runs both discover() and apply().
     *
     * @return static Returns self for method chaining
     *
     * @throws \Pollora\Discovery\Domain\Exceptions\DiscoveryException When discovery or application fails
     */
    public function run(): static;

    /**
     * Get a specific discovery by identifier
     *
     * @param  string  $identifier  The discovery identifier
     * @return DiscoveryInterface The discovery instance
     *
     * @throws \Pollora\Discovery\Domain\Exceptions\DiscoveryNotFoundException When discovery not found
     */
    public function getDiscovery(string $identifier): DiscoveryInterface;

    /**
     * Get all registered discoveries
     *
     * @return Collection<string, DiscoveryInterface> Collection of discoveries keyed by identifier
     */
    public function getDiscoveries(): Collection;

    /**
     * Get all discovery locations
     *
     * @return Collection<int, DiscoveryLocationInterface> Collection of discovery locations
     */
    public function getLocations(): Collection;

    /**
     * Enable caching for discoveries
     *
     * @param  mixed  $cache  The cache implementation (for compatibility)
     * @return static Returns self for method chaining
     */
    public function withCache(mixed $cache): static;

    /**
     * Clear all discovery caches
     *
     * @return static Returns self for method chaining
     */
    public function clearCache(): static;
}
