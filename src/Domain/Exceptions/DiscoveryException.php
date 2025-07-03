<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Exceptions;

use Exception;

/**
 * Base Discovery Exception
 *
 * Base exception class for all discovery-related errors.
 * Provides common functionality for discovery exceptions.
 *
 * @package Pollora\Discovery\Domain\Exceptions
 */
class DiscoveryException extends Exception
{
    /**
     * Create a new discovery exception
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for discovery process failure
     *
     * @param string $discoveryClass The discovery class that failed
     * @param \Throwable|null $previous Previous exception for chaining
     *
     * @return static
     */
    public static function discoveryFailed(string $discoveryClass, ?\Throwable $previous = null): static
    {
        return new static(
            message: "Discovery failed for class: {$discoveryClass}",
            previous: $previous
        );
    }

    /**
     * Create exception for discovery application failure
     *
     * @param string $discoveryClass The discovery class that failed to apply
     * @param \Throwable|null $previous Previous exception for chaining
     *
     * @return static
     */
    public static function applicationFailed(string $discoveryClass, ?\Throwable $previous = null): static
    {
        return new static(
            message: "Failed to apply discovery for class: {$discoveryClass}",
            previous: $previous
        );
    }
}