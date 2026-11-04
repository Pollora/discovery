<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Services;

use Pollora\Discovery\Domain\Contracts\InstanceResolverInterface;
use Pollora\Discovery\Domain\Exceptions\CircularDependencyException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Builds instances by reflection, with nothing but PHP itself.
 *
 * It is the last resort of {@see HasInstancePool}, kept in the domain so that
 * the trait does not depend on the infrastructure layer; the infrastructure's
 * ReflectionInstanceResolver is this builder under its public name.
 *
 * Constructor parameters are filled from their default value, or by
 * building the class they are typed with. A parameter that has neither
 * cannot be filled, and the class is reported as not instantiable.
 */
class ReflectionInstanceBuilder implements InstanceResolverInterface
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
            throw CircularDependencyException::whileBuilding($className);
        }

        $constructor = $reflection->getConstructor();

        if (! $constructor instanceof \ReflectionMethod || $constructor->getNumberOfParameters() === 0) {
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
