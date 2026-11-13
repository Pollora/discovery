<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Contracts\InstanceResolverInterface;
use Pollora\Discovery\Domain\Exceptions\InstantiationFailedException;
use Pollora\Discovery\Infrastructure\Services\CircularDependencyException;
use Pollora\Discovery\Infrastructure\Services\InstancePool;

final class PoolTestService
{
    public function __construct(public string $label = 'default') {}
}

final class PoolTestOther {}

/**
 * A resolver that builds the class and counts how often it was asked to.
 */
function poolResolver(): InstanceResolverInterface
{
    return new class implements InstanceResolverInterface
    {
        public int $calls = 0;

        public function make(string $className): object
        {
            $this->calls++;

            return new $className;
        }
    };
}

describe('getting instances', function (): void {
    it('builds an instance once, then hands out the same one', function (): void {
        $resolver = poolResolver();
        $pool = new InstancePool($resolver);

        $first = $pool->getInstance(PoolTestService::class);
        $second = $pool->getInstance(PoolTestService::class);

        expect($second)->toBe($first)
            ->and($resolver->calls)->toBe(1)
            ->and($pool->hasInstance(PoolTestService::class))->toBeTrue()
            ->and($pool->hasInstance(PoolTestOther::class))->toBeFalse();
    });

    it('builds by reflection when it is given no resolver', function (): void {
        expect((new InstancePool)->getInstance(PoolTestService::class))->toBeInstanceOf(PoolTestService::class);
    });

    it('wraps what the resolver throws, and does not keep the class as being built', function (): void {
        $resolver = Mockery::mock(InstanceResolverInterface::class);
        $resolver->shouldReceive('make')->andThrow(new LogicException('no binding'));
        $pool = new InstancePool($resolver);

        try {
            $pool->getInstance(PoolTestService::class);
            $this->fail('The failure was swallowed');
        } catch (InstantiationFailedException $exception) {
            expect($exception->getPrevious())->toBeInstanceOf(LogicException::class);
        }

        expect($pool->isInstantiating(PoolTestService::class))->toBeFalse()
            ->and($pool->hasInstance(PoolTestService::class))->toBeFalse()
            ->and($pool->getStats()['instantiation_errors'])->toBe(1);
    });

    it('detects a class that is asked for while it is being built', function (): void {
        $pool = null;
        $resolver = Mockery::mock(InstanceResolverInterface::class);
        $resolver->shouldReceive('make')->andReturnUsing(function (string $className) use (&$pool): object {
            expect($pool->isInstantiating($className))->toBeTrue();

            return $pool->getInstance($className);
        });
        $pool = new InstancePool($resolver);

        try {
            $pool->getInstance(PoolTestService::class);
            $this->fail('The loop was not detected');
        } catch (InstantiationFailedException $exception) {
            expect($exception->getPrevious())->toBeInstanceOf(CircularDependencyException::class)
                ->and($exception->getPrevious()->getMessage())->toBe('Circular dependency detected while instantiating: '.PoolTestService::class);
        }

        expect($pool->getStats()['circular_dependencies'])->toBe(1)
            ->and($pool->isInstantiating(PoolTestService::class))->toBeFalse();
    });
});

describe('preloading', function (): void {
    it('preloads what it can build and skips the rest', function (): void {
        $pool = new InstancePool(poolResolver());

        $loaded = $pool->preloadInstances([PoolTestService::class, 'App\DoesNotExist', PoolTestOther::class]);

        expect(array_keys($loaded))->toBe([PoolTestService::class, PoolTestOther::class])
            ->and($pool->getPooledClasses())->toBe([PoolTestService::class, PoolTestOther::class])
            ->and($pool->getPoolSize())->toBe(2);
    });
});

describe('removing and trimming', function (): void {
    it('removes an instance, and says whether there was one', function (): void {
        $pool = new InstancePool(poolResolver());
        $pool->getInstance(PoolTestService::class);

        expect($pool->removeInstance(PoolTestService::class))->toBeTrue()
            ->and($pool->removeInstance(PoolTestService::class))->toBeFalse()
            ->and($pool->getPoolSize())->toBe(0);
    });

    it('keeps the pool as it is when it is under the limit', function (): void {
        $pool = new InstancePool(poolResolver());
        $pool->getInstance(PoolTestService::class);

        $pool->optimize(5);

        expect($pool->getPoolSize())->toBe(1);
    });

    it('drops the oldest instances when it is over the limit', function (): void {
        $pool = new InstancePool(poolResolver());
        $pool->getInstance(PoolTestService::class);
        $pool->getInstance(PoolTestOther::class);

        $pool->optimize(1);

        expect($pool->getPooledClasses())->toBe([PoolTestOther::class]);
    });
});

describe('statistics', function (): void {
    it('has a zero hit ratio before any request', function (): void {
        expect((new InstancePool(poolResolver()))->getStats())->toBe([
            'pool_size' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'hit_ratio_percent' => 0.0,
            'circular_dependencies' => 0,
            'instantiation_errors' => 0,
            'total_requests' => 0,
        ]);
    });

    it('counts hits and misses, and resets them', function (): void {
        $pool = new InstancePool(poolResolver());
        $pool->getInstance(PoolTestService::class);
        $pool->getInstance(PoolTestService::class);
        $pool->getInstance(PoolTestService::class);

        $stats = $pool->getStats();
        $pool->resetStats();

        expect($stats['cache_hits'])->toBe(2)
            ->and($stats['cache_misses'])->toBe(1)
            ->and($stats['total_requests'])->toBe(3)
            ->and($stats['hit_ratio_percent'])->toBe(66.67)
            ->and($pool->getStats()['total_requests'])->toBe(0)
            ->and($pool->getPoolSize())->toBe(1);
    });

    it('describes its state for debugging, with a size estimate in human units', function (): void {
        $pool = new InstancePool(poolResolver());
        $pool->getInstance(PoolTestOther::class);

        $info = $pool->getDebugInfo();

        expect($info['instances'])->toBe([PoolTestOther::class])
            ->and($info['instantiating'])->toBe([])
            ->and($info['stats']['pool_size'])->toBe(1)
            ->and($info['memory_usage'])->toMatch('/^\d+(\.\d+)? B$/');
    });

    it('expresses a large pool in larger units', function (): void {
        $pool = new InstancePool(poolResolver());
        $pool->getInstance(PoolTestService::class)->label = str_repeat('x', 3000);

        expect($pool->getDebugInfo()['memory_usage'])->toMatch('/^\d+(\.\d+)? KB$/');
    });
});
