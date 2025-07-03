<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Contracts;

/**
 * Discovery Location Interface
 *
 * Represents a location where discovery should be performed, containing
 * the namespace and path information necessary for proper class resolution
 * and discovery context.
 *
 * @package Pollora\Discovery\Domain\Contracts
 */
interface DiscoveryLocationInterface
{
    /**
     * Get the base namespace for this location
     *
     * @return string The namespace associated with this location
     */
    public function getNamespace(): string;

    /**
     * Get the filesystem path for this location
     *
     * @return string The absolute path to the discovery location
     */
    public function getPath(): string;

    /**
     * Get a unique identifier for this location
     *
     * Used for caching and identification purposes.
     *
     * @return string The unique location identifier
     */
    public function getKey(): string;

    /**
     * Check if this location is within a vendor directory
     *
     * @return bool True if this is a vendor location, false otherwise
     */
    public function isVendor(): bool;

    /**
     * Convert a file path to a fully qualified class name
     *
     * @param string $filePath The path to the PHP file
     *
     * @return string The fully qualified class name
     */
    public function toClassName(string $filePath): string;
}