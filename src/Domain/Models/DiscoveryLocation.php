<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Models;

use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;

/**
 * Discovery Location
 *
 * Represents a location where discovery should be performed.
 * Contains the namespace and path information necessary for
 * proper class resolution and discovery context.
 */
final readonly class DiscoveryLocation implements DiscoveryLocationInterface
{
    /**
     * The resolved absolute path
     */
    public string $path;

    /**
     * Create a new discovery location
     *
     * @param  string  $namespace  The base namespace for this location
     * @param  string  $path  The filesystem path for this location
     */
    public function __construct(
        public string $namespace,
        string $path
    ) {
        $this->path = $this->resolvePath($path);
    }

    /**
     * {@inheritDoc}
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * {@inheritDoc}
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * {@inheritDoc}
     */
    public function getKey(): string
    {
        return (string) crc32($this->path);
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
        // Ensure the file path is within this location
        if (! str_starts_with($filePath, $this->path)) {
            return '';
        }

        // Convert file path to class name
        $relativePath = substr($filePath, strlen($this->path));
        $relativePath = ltrim($relativePath, '/\\');

        // Remove .php extension
        $relativePath = preg_replace('/\.php$/', '', $relativePath);

        // Convert path separators to namespace separators
        $className = str_replace(['/', '\\'], '\\', $relativePath);

        // Combine with base namespace
        return rtrim($this->namespace, '\\').'\\'.$className;
    }

    /**
     * Resolve the absolute path
     *
     * @param  string  $path  The path to resolve
     * @return string The resolved absolute path
     */
    private function resolvePath(string $path): string
    {
        $resolved = realpath(rtrim($path, '\\/'));

        return $resolved !== false ? $resolved : rtrim($path, '\\/');
    }
}
