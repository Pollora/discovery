<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Resolvers;

use Pollora\Discovery\Domain\Contracts\InstanceResolverInterface;
use Pollora\Discovery\Domain\Exceptions\InstantiationFailedException;
use Psr\Container\ContainerInterface;

/**
 * Resolves instances through a PSR-11 container.
 *
 * Laravel's container answers `get()` for unbound concrete classes too,
 * building them with their dependencies, so it behaves as `make()` does.
 */
final readonly class ContainerInstanceResolver implements InstanceResolverInterface
{
    public function __construct(
        private ContainerInterface $container
    ) {}

    public function make(string $className): object
    {
        $instance = $this->container->get($className);

        if (! $instance instanceof $className) {
            throw InstantiationFailedException::forClass(
                $className,
                new \UnexpectedValueException(sprintf('The container returned %s instead of %s.', get_debug_type($instance), $className))
            );
        }

        return $instance;
    }
}
