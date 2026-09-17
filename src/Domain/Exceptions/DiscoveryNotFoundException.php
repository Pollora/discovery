<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Exceptions;

/**
 * Discovery Not Found Exception
 *
 * Thrown when a requested discovery class cannot be found
 * in the discovery registry or engine.
 */
final class DiscoveryNotFoundException extends DiscoveryException
{
    /**
     * Create exception for missing discovery
     *
     * @param  string  $identifier  The discovery identifier that was not found
     */
    public static function withIdentifier(string $identifier): self
    {
        return new self('Discovery not found with identifier: '.$identifier);
    }

    /**
     * Create exception for missing discovery class
     *
     * @param  string  $discoveryClass  The discovery class that was not found
     */
    public static function withClass(string $discoveryClass): self
    {
        return new self('Discovery class not found: '.$discoveryClass);
    }
}
