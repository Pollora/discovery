<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Models\DiscoveryContext;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredEnum;

final class ContextTestConcrete {}

abstract class ContextTestAbstract {}

enum ContextTestEnum
{
    case One;
}

function contextWith(?ReflectionCacheInterface $cache = null): DiscoveryContext
{
    return new DiscoveryContext($cache ?? Mockery::mock(ReflectionCacheInterface::class));
}

describe('processed classes', function (): void {
    it('remembers which discovery processed which class', function (): void {
        $context = contextWith();

        $context->markProcessed('App\A', 'hooks');
        $context->markProcessed('App\A', 'routes');

        expect($context->isProcessed('App\A', 'hooks'))->toBeTrue()
            ->and($context->isProcessed('App\A', 'other'))->toBeFalse()
            ->and($context->isProcessed('App\B', 'hooks'))->toBeFalse()
            ->and($context->getProcessedDiscoveries('App\A'))->toBe(['hooks', 'routes'])
            ->and($context->getProcessedDiscoveries('App\B'))->toBe([]);
    });

    it('tells whether any discovery processed a class', function (): void {
        $context = contextWith();
        $context->markProcessed('App\A', 'hooks');

        expect($context->isClassProcessed('App\A'))->toBeTrue()
            ->and($context->isClassProcessed('App\B'))->toBeFalse();
    });
});

describe('shared data', function (): void {
    it('shares data between discoveries, by class and key', function (): void {
        $context = contextWith();
        $context->setSharedData('App\A', 'route', '/home');
        $context->setSharedData('App\A', 'nothing', null);

        expect($context->getSharedData('App\A', 'route'))->toBe('/home')
            ->and($context->getSharedData('App\A'))->toBe(['route' => '/home', 'nothing' => null])
            ->and($context->getSharedData('App\A', 'missing'))->toBeNull()
            ->and($context->getSharedData('App\B'))->toBe([])
            ->and($context->hasSharedData('App\A', 'route'))->toBeTrue()
            ->and($context->hasSharedData('App\A', 'nothing'))->toBeFalse()
            ->and($context->hasSharedData('App\B', 'route'))->toBeFalse();
    });
});

describe('reflection cache', function (): void {
    it('exposes the reflection cache it was given', function (): void {
        $cache = Mockery::mock(ReflectionCacheInterface::class);

        expect(contextWith($cache)->getReflectionCache())->toBe($cache);
    });

    it('preloads concrete classes only, ignoring abstract classes and enums', function (): void {
        $received = null;
        $cache = Mockery::mock(ReflectionCacheInterface::class);
        $cache->shouldReceive('preloadClasses')->once()->andReturnUsing(function (array $names) use (&$received): void {
            $received = $names;
        });

        contextWith($cache)->preloadStructures([
            DiscoveredClass::fromReflection(new ReflectionClass(ContextTestConcrete::class)),
            DiscoveredClass::fromReflection(new ReflectionClass(ContextTestAbstract::class)),
            DiscoveredEnum::fromReflection(new ReflectionEnum(ContextTestEnum::class)),
        ]);

        // A class of the global namespace is named '\Name' by the discoverer.
        expect($received)->toBe(['\\'.ContextTestConcrete::class]);
    });
});

describe('statistics', function (): void {
    it('starts at zero', function (): void {
        expect(contextWith()->getStats())->toBe([
            'classes_processed' => 0,
            'discoveries_executed' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'errors' => 0,
        ]);
    });

    it('increments a counter, by one or by more, and creates the ones it does not know', function (): void {
        $context = contextWith();

        $context->incrementStat('classes_processed');
        $context->incrementStat('classes_processed', 4);
        $context->incrementStat('custom', 2);

        expect($context->getStats()['classes_processed'])->toBe(5)
            ->and($context->getStats()['custom'])->toBe(2);
    });

    it('records cache hits, misses and errors', function (): void {
        $context = contextWith();

        $context->recordCacheHit();
        $context->recordCacheMiss();
        $context->recordCacheMiss();
        $context->recordError();

        expect($context->getStats())->toMatchArray(['cache_hits' => 1, 'cache_misses' => 2, 'errors' => 1]);
    });

    it('computes the cache efficiency as a percentage, zero without any request', function (): void {
        $context = contextWith();

        expect($context->getCacheEfficiency())->toBe(0.0);

        $context->recordCacheHit();
        $context->recordCacheMiss();
        $context->recordCacheMiss();

        expect($context->getCacheEfficiency())->toBe(33.33);
    });

    it('summarises the state of the discovery', function (): void {
        $context = contextWith();
        $context->markProcessed('App\A', 'hooks');
        $context->markProcessed('App\A', 'routes');
        $context->markProcessed('App\B', 'hooks');
        $context->setSharedData('App\A', 'k', 1);
        $context->recordCacheHit();

        $summary = $context->getSummary();

        expect($summary['total_classes'])->toBe(2)
            ->and($summary['total_discovery_executions'])->toBe(3)
            ->and($summary['shared_data_entries'])->toBe(1)
            ->and($summary['cache_efficiency'])->toBe(100.0)
            ->and($summary['stats']['cache_hits'])->toBe(1);
    });
});
