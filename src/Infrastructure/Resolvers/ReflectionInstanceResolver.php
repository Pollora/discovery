<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Resolvers;

use Pollora\Discovery\Domain\Contracts\InstanceResolverInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Builds instances by reflection, for environments without a container.
 *
 * Constructor parameters are filled from their default value, or by
 * building the class they are typed with. A parameter that has neither
 * cannot be filled, and the class is reported as not instantiable.
 */
final class ReflectionInstanceResolver implements InstanceResolverInterface
{
    /**
     * Classes being built, to stop a dependency cycle before it overflows the stack.
     *
     * @var array<class-string, true>
     */
    private array $building = [];

    public function make(string $className): object
    {
        $reflection = new ReflectionClass($className);

        if (! $reflection->isInstantiable()) {
            throw new \InvalidArgumentException(sprintf('Class %s is not instantiable', $className));
        }

        if (isset($this->building[$className])) {
            throw new \RuntimeException(sprintf('Circular dependency detected while building %s', $className));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }

        $this->building[$className] = true;

        try {
            return $reflection->newInstanceArgs(array_map(
                fn (ReflectionParameter $parameter): mixed => $this->resolveParameter($className, $parameter),
                $constructor->getParameters()
            ));
        } finally {
            unset($this->building[$className]);
        }
    }

    private function resolveParameter(string $className, ReflectionParameter $parameter): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            /** @var class-string $dependency */
            $dependency = $type->getName();

            return $this->make($dependency);
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new \InvalidArgumentException(sprintf(
            'Cannot build %s: parameter $%s has no default value and no class type',
            $className,
            $parameter->getName()
        ));
    }
}
