<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Models;

use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;

/**
 * Directory Location
 *
 * Simple implementation of DiscoveryLocationInterface for directory-based discovery.
 * This class represents a filesystem directory where discovery should be performed.
 *
 * @package Pollora\Discovery\Domain\Models
 */
final readonly class DirectoryLocation implements DiscoveryLocationInterface
{
    /**
     * Create a new directory location
     *
     * @param string $path The filesystem path to discover in
     * @param string|null $namespace Optional namespace (defaults to empty)
     */
    public function __construct(
        private string $path,
        private ?string $namespace = null
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getNamespace(): string
    {
        return $this->namespace ?? '';
    }

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
        return md5($this->namespace . ':' . $this->path);
    }

    /**
     * {@inheritDoc}
     */
    public function isVendor(): bool
    {
        return str_contains($this->path, '/vendor/') || str_contains($this->path, '\\vendor\\');
    }

    /**
     * {@inheritDoc}
     */
    public function toClassName(string $filePath): string
    {
        // If no namespace is provided, we can't convert to class name
        if (empty($this->namespace)) {
            return '';
        }

        // Get relative path from the base path
        $relativePath = str_replace($this->path, '', $filePath);
        $relativePath = ltrim($relativePath, '/\\');
        
        // Remove .php extension
        $relativePath = preg_replace('/\.php$/', '', $relativePath);
        
        // Convert path separators to namespace separators
        $classPath = str_replace(['/', '\\'], '\\', $relativePath);
        
        // Combine namespace with class path
        return $this->namespace . '\\' . $classPath;
    }

    /**
     * Get a string representation of the location
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->namespace ? "{$this->namespace}:{$this->path}" : $this->path;
    }
}