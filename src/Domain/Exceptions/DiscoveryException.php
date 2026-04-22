<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Exceptions;

use Exception;

/**
 * Base Discovery Exception
 *
 * Base exception class for all discovery-related errors.
 * Provides common functionality for discovery exceptions.
 */
class DiscoveryException extends Exception
{
    /**
     * Create exception for discovery process failure
     *
     * @param  string  $discoveryClass  The discovery class that failed
     * @param  \Throwable|null  $previous  Previous exception for chaining
     */
    public static function discoveryFailed(string $discoveryClass, ?\Throwable $previous = null): static
    {
        return new static(
            message: 'Discovery failed for class: '.$discoveryClass,
            previous: $previous
        );
    }

    /**
     * Create exception for discovery application failure
     *
     * @param  string  $discoveryClass  The discovery class that failed to apply
     * @param  \Throwable|null  $previous  Previous exception for chaining
     */
    public static function applicationFailed(string $discoveryClass, ?\Throwable $previous = null): static
    {
        return new static(
            message: 'Failed to apply discovery for class: '.$discoveryClass,
            previous: $previous
        );
    }
}
