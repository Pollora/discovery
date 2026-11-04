<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when building a class needs the class being built.
 */
final class CircularDependency extends RuntimeException
{
    /**
     * @param  string  $className  The class whose construction loops back to itself
     */
    public static function whileBuilding(string $className): self
    {
        return new self(sprintf(
            'Circular dependency detected while building %s. Break the loop: inject a factory or an interface instead of one of the classes involved.',
            $className,
        ));
    }
}
