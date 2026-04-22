<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Exceptions;

/**
 * Invalid Discovery Exception
 *
 * Thrown when a discovery class is invalid or doesn't implement
 * the required interfaces properly.
 */
class InvalidDiscoveryException extends DiscoveryException
{
    /**
     * Create exception for invalid discovery class
     *
     * @param  string  $discoveryClass  The invalid discovery class
     * @param  string  $reason  The reason why it's invalid
     */
    public static function invalidClass(string $discoveryClass, string $reason): static
    {
        return new static(sprintf("Invalid discovery class '%s': %s", $discoveryClass, $reason));
    }

    /**
     * Create exception for discovery class not implementing required interface
     *
     * @param  string  $discoveryClass  The discovery class
     * @param  string  $requiredInterface  The required interface
     */
    public static function missingInterface(string $discoveryClass, string $requiredInterface): static
    {
        return new static(
            sprintf("Discovery class '%s' must implement '%s'", $discoveryClass, $requiredInterface)
        );
    }

    /**
     * Create exception for duplicate discovery identifier
     *
     * @param  string  $identifier  The duplicate identifier
     */
    public static function duplicateIdentifier(string $identifier): static
    {
        return new static(sprintf("Discovery identifier '%s' is already registered", $identifier));
    }
}
