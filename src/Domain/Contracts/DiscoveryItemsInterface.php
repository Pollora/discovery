<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Contracts;

use Countable;
use IteratorAggregate;

/**
 * Discovery Items Interface
 *
 * Represents a collection of discovered items organized by discovery location.
 * Provides methods to add, retrieve, and manage discovered items with
 * support for location-based organization and caching.
 *
 * @extends IteratorAggregate<int, mixed>
 */
interface DiscoveryItemsInterface extends Countable, IteratorAggregate
{
    /**
     * Add multiple values for a specific location
     *
     * @param  DiscoveryLocationInterface  $location  The discovery location
     * @param  array<mixed>  $values  The values to add
     * @return static Returns self for method chaining
     */
    public function addForLocation(DiscoveryLocationInterface $location, array $values): static;

    /**
     * Get all values for a specific location
     *
     * @param  DiscoveryLocationInterface  $location  The discovery location
     * @return array<mixed> The values for the location
     */
    public function getForLocation(DiscoveryLocationInterface $location): array;

    /**
     * Add a single value for a specific location
     *
     * @param  DiscoveryLocationInterface  $location  The discovery location
     * @param  mixed  $value  The value to add
     * @return static Returns self for method chaining
     */
    public function add(DiscoveryLocationInterface $location, mixed $value): static;

    /**
     * Check if a location has any discovered items
     *
     * @param  DiscoveryLocationInterface  $location  The discovery location
     * @return bool True if the location has items, false otherwise
     */
    public function hasLocation(DiscoveryLocationInterface $location): bool;

    /**
     * Check if any items have been loaded
     *
     * @return bool True if items are loaded, false if empty
     */
    public function isLoaded(): bool;

    /**
     * Filter to only include vendor locations
     *
     * Creates a new instance containing only items from vendor directories.
     *
     * @return static A new instance with only vendor items
     */
    public function onlyVendor(): static;

    /**
     * Get all items flattened into a single array
     *
     * @return array<mixed> All discovered items
     */
    public function all(): array;
}
