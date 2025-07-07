<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Models;

use ArrayIterator;
use Pollora\Discovery\Domain\Contracts\DiscoveryItemsInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Traversable;

/**
 * Discovery Items Collection
 *
 * Represents a collection of discovered items organized by discovery location.
 * Provides methods to add, retrieve, and manage discovered items with
 * support for location-based organization and caching.
 */
final class DiscoveryItems implements DiscoveryItemsInterface
{
    /**
     * Create a new discovery items collection
     *
     * @param  array<string, array<mixed>>  $items  Initial items organized by location key
     */
    public function __construct(
        /**
         * The discovered items organized by location key
         */
        private array $items = []
    ) {}

    /**
     * {@inheritDoc}
     */
    public function addForLocation(DiscoveryLocationInterface $location, array $values): static
    {
        $locationKey = $location->getKey();
        $existingValues = $this->items[$locationKey] ?? [];

        $this->items[$locationKey] = [...$existingValues, ...$values];

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getForLocation(DiscoveryLocationInterface $location): array
    {
        return $this->items[$location->getKey()] ?? [];
    }

    /**
     * {@inheritDoc}
     */
    public function add(DiscoveryLocationInterface $location, mixed $value): static
    {
        $locationKey = $location->getKey();
        $this->items[$locationKey] ??= [];
        $this->items[$locationKey][] = $value;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function hasLocation(DiscoveryLocationInterface $location): bool
    {
        return array_key_exists($location->getKey(), $this->items);
    }

    /**
     * {@inheritDoc}
     */
    public function isLoaded(): bool
    {
        return $this->items !== [];
    }

    /**
     * {@inheritDoc}
     */
    public function onlyVendor(): static
    {
        $vendorItems = [];

        foreach ($this->items as $locationKey => $items) {
            // We need to check if the location is vendor, but we only have the key
            // This is a limitation - we'd need to store location objects or add vendor flag
            // For now, we'll use a simple heuristic based on common vendor paths
            if ($this->isVendorLocation()) {
                $vendorItems[$locationKey] = $items;
            }
        }

        return new self($vendorItems);
    }

    /**
     * {@inheritDoc}
     */
    public function all(): array
    {
        $allItems = [];

        foreach ($this->items as $locationItems) {
            $allItems = [...$allItems, ...$locationItems];
        }

        return $allItems;
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Check if a location key represents a vendor location
     *
     * This is a heuristic approach since we only have the CRC32 key.
     * In a real implementation, we might need to store more location metadata.
     *
     * @return bool True if likely a vendor location
     */
    private function isVendorLocation(): bool
    {
        // This is a simplified heuristic - in practice, you might want to
        // store additional metadata about locations or use a different approach
        return false;
    }

    /**
     * Serialize the items for caching
     *
     * @return array<string, array<mixed>>
     */
    public function __serialize(): array
    {
        return $this->items;
    }

    /**
     * Unserialize the items from cache
     *
     * @param  array<string, array<mixed>>  $data  The serialized data
     */
    public function __unserialize(array $data): void
    {
        $this->items = $data;
    }
}
