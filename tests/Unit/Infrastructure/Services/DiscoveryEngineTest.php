<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Pollora\Application\Domain\Contracts\DebugDetectorInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Exceptions\DiscoveryNotFoundException;
use Pollora\Discovery\Domain\Exceptions\InvalidDiscoveryException;
use Pollora\Discovery\Infrastructure\Services\DiscoveryCacheManager;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;

function createEngine(?DiscoveryCacheManager $cacheManager = null): DiscoveryEngine
{
    $container = new Container;
    $debugDetector = Mockery::mock(DebugDetectorInterface::class, ['isDebugMode' => true]);

    return new DiscoveryEngine(
        $container,
        $debugDetector,
        cacheManager: $cacheManager ?? Mockery::mock(DiscoveryCacheManager::class)
    );
}

function createLocationMock(string $path): DiscoveryLocationInterface
{
    $location = Mockery::mock(DiscoveryLocationInterface::class);
    $location->shouldReceive('getPath')->andReturn($path);
    $location->shouldReceive('getKey')->andReturn('key_'.md5($path));
    $location->shouldReceive('isVendor')->andReturn(false);

    return $location;
}

describe('DiscoveryEngine', function (): void {
    describe('addLocation', function (): void {
        it('adds a location', function (): void {
            $engine = createEngine();
            $location = createLocationMock('/tmp/test-path-'.uniqid());

            $result = $engine->addLocation($location);

            expect($result)->toBe($engine);
            expect($engine->getLocations())->toHaveCount(1);
        });

        it('deduplicates locations with same path', function (): void {
            $engine = createEngine();
            $path = '/tmp/dedup-path-'.uniqid();
            $location1 = createLocationMock($path);
            $location2 = createLocationMock($path);

            $engine->addLocation($location1);
            $engine->addLocation($location2);

            expect($engine->getLocations())->toHaveCount(1);
        });

        it('adds locations with different paths', function (): void {
            $engine = createEngine();

            $engine->addLocation(createLocationMock('/tmp/path-a-'.uniqid()));
            $engine->addLocation(createLocationMock('/tmp/path-b-'.uniqid()));

            expect($engine->getLocations())->toHaveCount(2);
        });
    });

    describe('addLocations', function (): void {
        it('adds multiple locations at once', function (): void {
            $engine = createEngine();

            $engine->addLocations([
                createLocationMock('/tmp/loc-1-'.uniqid()),
                createLocationMock('/tmp/loc-2-'.uniqid()),
            ]);

            expect($engine->getLocations())->toHaveCount(2);
        });
    });

    describe('addDiscovery', function (): void {
        it('adds a discovery instance', function (): void {
            $engine = createEngine();
            $discovery = Mockery::mock(DiscoveryInterface::class);

            $engine->addDiscovery('test', $discovery);

            expect($engine->getDiscoveries())->toHaveCount(1);
            expect($engine->getDiscoveries()->has('test'))->toBeTrue();
        });

        it('throws on duplicate identifier', function (): void {
            $engine = createEngine();
            $discovery = Mockery::mock(DiscoveryInterface::class);

            $engine->addDiscovery('dup', $discovery);

            expect(fn () => $engine->addDiscovery('dup', $discovery))
                ->toThrow(InvalidDiscoveryException::class);
        });
    });

    describe('addDiscoveries', function (): void {
        it('adds multiple discoveries at once', function (): void {
            $engine = createEngine();

            $engine->addDiscoveries([
                'first' => Mockery::mock(DiscoveryInterface::class),
                'second' => Mockery::mock(DiscoveryInterface::class),
            ]);

            expect($engine->getDiscoveries())->toHaveCount(2);
        });
    });

    describe('getDiscovery', function (): void {
        it('returns discovery by identifier', function (): void {
            $engine = createEngine();
            $discovery = Mockery::mock(DiscoveryInterface::class);

            $engine->addDiscovery('my-discovery', $discovery);

            expect($engine->getDiscovery('my-discovery'))->toBe($discovery);
        });

        it('throws when discovery not found', function (): void {
            $engine = createEngine();

            expect(fn () => $engine->getDiscovery('nonexistent'))
                ->toThrow(DiscoveryNotFoundException::class);
        });
    });

    describe('clearLocations', function (): void {
        it('removes all locations', function (): void {
            $engine = createEngine();
            $engine->addLocation(createLocationMock('/tmp/clear-'.uniqid()));

            expect($engine->getLocations())->toHaveCount(1);

            $engine->clearLocations();

            expect($engine->getLocations())->toHaveCount(0);
        });

        it('returns the engine for chaining', function (): void {
            $engine = createEngine();

            expect($engine->clearLocations())->toBe($engine);
        });
    });

    describe('clearCache', function (): void {
        it('delegates to cache manager', function (): void {
            $cacheManager = Mockery::mock(DiscoveryCacheManager::class);
            $cacheManager->shouldReceive('clearCache')->once();

            $engine = createEngine($cacheManager);
            $engine->clearCache();
        });
    });

    describe('getPerformanceStats', function (): void {
        it('returns stats array with expected keys', function (): void {
            $cacheManager = Mockery::mock(DiscoveryCacheManager::class);
            $cacheManager->shouldReceive('getStaticCacheSize')->andReturn(0);

            $engine = createEngine($cacheManager);
            $stats = $engine->getPerformanceStats();

            expect($stats)->toHaveKeys(['context', 'instance_pool', 'static_cache_size']);
            expect($stats['static_cache_size'])->toBe(0);
        });
    });

    describe('__clone', function (): void {
        it('creates independent copies of collections', function (): void {
            $engine = createEngine();
            $engine->addLocation(createLocationMock('/tmp/clone-'.uniqid()));

            $clone = clone $engine;
            $clone->addLocation(createLocationMock('/tmp/clone-extra-'.uniqid()));

            expect($engine->getLocations())->toHaveCount(1);
            expect($clone->getLocations())->toHaveCount(2);
        });
    });
});
