<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Exceptions;

/**
 * Discovery Not Found Exception
 *
 * Thrown when a requested discovery class cannot be found
 * in the discovery registry or engine.
 *
 * @package Pollora\Discovery\Domain\Exceptions
 */
class DiscoveryNotFoundException extends DiscoveryException
{
    /**
     * Create exception for missing discovery
     *
     * @param string $identifier The discovery identifier that was not found
     *
     * @return static
     */
    public static function withIdentifier(string $identifier): static
    {
        return new static("Discovery not found with identifier: {$identifier}");
    }

    /**
     * Create exception for missing discovery class
     *
     * @param string $discoveryClass The discovery class that was not found
     *
     * @return static
     */
    public static function withClass(string $discoveryClass): static
    {
        return new static("Discovery class not found: {$discoveryClass}");
    }
}