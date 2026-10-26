<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a discovered class cannot be built: its constructor failed, or one of its dependencies cannot be resolved.
 */
final class InstantiationFailed extends RuntimeException
{
    /**
     * @param  string  $className  The class that could not be instantiated
     * @param  Throwable  $previous  Why; its code is kept
     */
    public static function forClass(string $className, Throwable $previous): self
    {
        return new self(
            sprintf('Failed to instantiate class %s: %s', $className, $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }
}
