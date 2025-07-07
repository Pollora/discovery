<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Contracts;

/**
 * Discovery Cache Interface
 *
 * Defines the contract for caching discovered items to improve
 * performance by avoiding repeated discovery operations.
 */
interface DiscoveryCacheInterface
{
    /**
     * Check if cached data exists for the given key
     *
     * @param  string  $key  The cache key to check
     * @return bool True if cached data exists, false otherwise
     */
    public function has(string $key): bool;

    /**
     * Retrieve cached discovery items
     *
     * @param  string  $key  The cache key
     * @return DiscoveryItemsInterface|null The cached discovery items or null if not found
     */
    public function get(string $key): ?DiscoveryItemsInterface;

    /**
     * Store discovery items in cache
     *
     * @param  string  $key  The cache key
     * @param  DiscoveryItemsInterface  $items  The discovery items to cache
     * @param  int|null  $ttl  Optional time-to-live in seconds
     * @return bool True if successfully cached, false otherwise
     */
    public function put(string $key, DiscoveryItemsInterface $items, ?int $ttl = null): bool;

    /**
     * Remove cached data for the given key
     *
     * @param  string  $key  The cache key to remove
     * @return bool True if removed, false if key didn't exist
     */
    public function forget(string $key): bool;

    /**
     * Clear all cached discovery data
     *
     * @return bool True if cache was cleared, false otherwise
     */
    public function flush(): bool;

    /**
     * Generate a cache key for a discovery
     *
     * @param  string  $discoveryIdentifier  The discovery identifier
     * @param  array<DiscoveryLocationInterface>  $locations  The discovery locations
     * @return string The generated cache key
     */
    public function generateKey(string $discoveryIdentifier, array $locations): string;
}
