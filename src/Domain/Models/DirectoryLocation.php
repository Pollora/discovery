<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Models;

use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;

/**
 * Directory Location
 *
 * Simple implementation of DiscoveryLocationInterface for directory-based discovery.
 * This class represents a filesystem directory where discovery should be performed.
 */
final readonly class DirectoryLocation implements \Stringable, DiscoveryLocationInterface
{
    /**
     * Create a new directory location
     *
     * @param  string  $path  The filesystem path to discover in
     */
    public function __construct(
        private string $path
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Check if the directory exists
     *
     * @return bool True if the directory exists and is readable
     */
    public function exists(): bool
    {
        return is_dir($this->path) && is_readable($this->path);
    }

    /**
     * {@inheritDoc}
     */
    public function getKey(): string
    {
        return md5($this->path);
    }

    /**
     * {@inheritDoc}
     */
    public function isVendor(): bool
    {
        return str_contains($this->path, '/vendor/') || str_contains($this->path, '\\vendor\\');
    }

    /**
     * Get a string representation of the location
     *
     * @return string The string representation of the location
     */
    public function __toString(): string
    {
        return $this->path;
    }
}
