<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Resolvers;

use Pollora\Discovery\Domain\Contracts\InstanceResolverInterface;
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
        return $this->container->get($className);
    }
}
