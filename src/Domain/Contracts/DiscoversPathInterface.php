<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Contracts;

/**
 * Path Discovery Interface
 *
 * Extends the basic Discovery interface to support discovery of files
 * that are not PHP structures (e.g., templates, assets, config files).
 *
 * Discovery classes implementing this interface can discover both
 * PHP structures through the discover() method and arbitrary files
 * through the discoverPath() method.
 */
interface DiscoversPathInterface extends DiscoveryInterface
{
    /**
     * Discover files based on file path
     *
     * This method is called for each file in the discovery locations
     * and allows the discovery class to examine file paths and collect
     * relevant items based on file characteristics.
     *
     * @param  DiscoveryLocationInterface  $location  The discovery location context
     * @param  string  $path  The absolute path to the file being examined
     */
    public function discoverPath(DiscoveryLocationInterface $location, string $path): void;
}
