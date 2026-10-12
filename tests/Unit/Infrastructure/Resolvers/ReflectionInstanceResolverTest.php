<?php

declare(strict_types=1);

use Pollora\Discovery\Infrastructure\Resolvers\ReflectionInstanceResolver;

final class ResolverLeaf {}

final class ResolverWithDefaults
{
    public function __construct(public string $name = 'default', public ?int $limit = null) {}
}

final class ResolverWithDependency
{
    public function __construct(public ResolverLeaf $leaf, public ?string $label) {}
}

final class ResolverWithScalar
{
    public function __construct(public string $required) {}
}

final class ResolverWithInterface
{
    public function __construct(public Countable $items) {}
}

final class ResolverCycleA
{
    public function __construct(public ResolverCycleB $b) {}
}

final class ResolverCycleB
{
    public function __construct(public ResolverCycleA $a) {}
}

abstract class ResolverAbstract {}

describe('ReflectionInstanceResolver', function (): void {
    it('builds a class without a constructor', function (): void {
        expect((new ReflectionInstanceResolver)->make(ResolverLeaf::class))->toBeInstanceOf(ResolverLeaf::class);
    });

    it('fills parameters from their default value', function (): void {
        $instance = (new ReflectionInstanceResolver)->make(ResolverWithDefaults::class);

        expect($instance->name)->toBe('default')
            ->and($instance->limit)->toBeNull();
    });

    it('builds class-typed dependencies and passes null to nullable scalars', function (): void {
        $instance = (new ReflectionInstanceResolver)->make(ResolverWithDependency::class);

        expect($instance->leaf)->toBeInstanceOf(ResolverLeaf::class)
            ->and($instance->label)->toBeNull();
    });

    it('names the parameter it cannot fill', function (): void {
        (new ReflectionInstanceResolver)->make(ResolverWithScalar::class);
    })->throws(InvalidArgumentException::class, 'parameter $required');

    it('refuses an interface dependency it has no implementation for', function (): void {
        (new ReflectionInstanceResolver)->make(ResolverWithInterface::class);
    })->throws(InvalidArgumentException::class, 'Countable is not instantiable');

    it('refuses an abstract class', function (): void {
        (new ReflectionInstanceResolver)->make(ResolverAbstract::class);
    })->throws(InvalidArgumentException::class, 'not instantiable');

    it('stops a dependency cycle instead of overflowing the stack', function (): void {
        (new ReflectionInstanceResolver)->make(ResolverCycleA::class);
    })->throws(RuntimeException::class, 'Circular dependency detected while building ResolverCycleA');

    it('can build the same class again after a failure', function (): void {
        $resolver = new ReflectionInstanceResolver;

        try {
            $resolver->make(ResolverCycleA::class);
        } catch (RuntimeException) {
            // The cycle is reported once; the resolver must not stay stuck on it.
        }

        expect($resolver->make(ResolverWithDependency::class))->toBeInstanceOf(ResolverWithDependency::class);
    });
});
