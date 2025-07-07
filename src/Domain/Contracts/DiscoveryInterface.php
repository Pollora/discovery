<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Contracts;

use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * Core Discovery Interface
 *
 * Defines the contract for discovery classes that can discover and apply
 * discovered structures. Inspired by Tempest Framework's Discovery pattern
 * while leveraging Spatie's structure discovery capabilities.
 *
 * Discovery classes are responsible for:
 * - Discovering specific structures (classes, interfaces, files, etc.)
 * - Collecting discovery items during the discovery phase
 * - Applying the discovered items to register/configure services
 */
interface DiscoveryInterface
{
    /**
     * Discover structures based on the given discovered structure
     *
     * This method is called for each discovered structure and allows
     * the discovery class to examine and collect relevant items.
     *
     * @param  DiscoveryLocationInterface  $location  The discovery location context
     * @param  DiscoveredStructure  $structure  The discovered structure to examine
     */
    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure): void;

    /**
     * Get the collected discovery items
     *
     * Returns all items that have been collected during the discovery process.
     *
     * @return DiscoveryItemsInterface The collected discovery items
     */
    public function getItems(): DiscoveryItemsInterface;

    /**
     * Set the discovery items collection
     *
     * Allows setting a pre-populated discovery items collection,
     * useful for caching and loading from storage.
     *
     * @param  DiscoveryItemsInterface  $items  The discovery items to set
     */
    public function setItems(DiscoveryItemsInterface $items): void;

    /**
     * Apply the discovered items
     *
     * This method is called after discovery is complete to apply/register
     * all discovered items with the application (service container, registries, etc.).
     *
     *
     * @throws \Pollora\Discovery\Domain\Exceptions\DiscoveryException When application fails
     */
    public function apply(): void;

    /**
     * Get the unique identifier for this discovery class
     *
     * Used for caching and identification purposes.
     *
     * @return string The unique discovery identifier
     */
    public function getIdentifier(): string;
}
