<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Pollora\Discovery\Application\Services\DiscoveryManager;
use Pollora\Discovery\Domain\Contracts\DiscoveryEngineInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Exceptions\DiscoveryNotFoundException;
use Pollora\Discovery\Domain\Models\DirectoryLocation;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use Pollora\Discovery\Infrastructure\Services\DiscoveryCacheManager;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * A discovery that remembers what it was shown and applied.
 */
final class ManagerTestDiscovery implements DiscoveryInterface
{
    use IsDiscovery;

    /** @var list<string> */
    public array $seen = [];

    public int $applied = 0;

    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
    {
        $this->seen[] = $structure->name;
        $this->getItems()->add($location, $structure->name);
    }

    public function apply(): void
    {
        $this->applied++;
    }
}

final class ManagerTestStructureClass {}

function managerEngine(array $structures = []): DiscoveryEngine
{
    $cacheManager = Mockery::mock(DiscoveryCacheManager::class);
    $cacheManager->shouldReceive('getStructuresForLocation')->andReturn($structures);
    $cacheManager->shouldReceive('clearCache');
    $cacheManager->shouldReceive('getStaticCacheSize')->andReturn(0);

    return new DiscoveryEngine(cacheManager: $cacheManager);
}

describe('delegating to the engine', function (): void {
    it('hands locations to the engine as directories, and chains', function (): void {
        $engine = Mockery::mock(DiscoveryEngineInterface::class);
        $paths = [];
        $engine->shouldReceive('addLocation')->twice()->andReturnUsing(function (DirectoryLocation $location) use (&$paths, $engine) {
            $paths[] = $location->getPath();

            return $engine;
        });
        $manager = new DiscoveryManager($engine);

        expect($manager->addLocation('/a'))->toBe($manager)
            ->and($manager->addLocations(['/b']))->toBe($manager)
            ->and($paths)->toBe(['/a', '/b']);
    });

    it('registers discoveries one by one or in bulk', function (): void {
        $discovery = new ManagerTestDiscovery;
        $engine = Mockery::mock(DiscoveryEngineInterface::class);
        $engine->shouldReceive('addDiscovery')->once()->with('one', $discovery);
        $engine->shouldReceive('addDiscoveries')->once()->with(['two' => ManagerTestDiscovery::class]);
        $manager = new DiscoveryManager($engine);

        expect($manager->addDiscovery('one', $discovery))->toBe($manager)
            ->and($manager->addDiscoveries(['two' => ManagerTestDiscovery::class]))->toBe($manager);
    });

    it('runs, discovers, applies and clears the cache through the engine, and chains', function (): void {
        $engine = Mockery::mock(DiscoveryEngineInterface::class);
        $engine->shouldReceive('run')->once();
        $engine->shouldReceive('discover')->once();
        $engine->shouldReceive('apply')->once();
        $engine->shouldReceive('clearCache')->once();
        $manager = new DiscoveryManager($engine);

        expect($manager->run())->toBe($manager)
            ->and($manager->discover())->toBe($manager)
            ->and($manager->apply())->toBe($manager)
            ->and($manager->clearCache())->toBe($manager);
    });

    it('reads discoveries and locations from the engine', function (): void {
        $discovery = new ManagerTestDiscovery;
        $discoveries = new Collection(['one' => $discovery]);
        $locations = new Collection([new DirectoryLocation('/a')]);
        $engine = Mockery::mock(DiscoveryEngineInterface::class);
        $engine->shouldReceive('getDiscovery')->with('one')->andReturn($discovery);
        $engine->shouldReceive('getDiscoveries')->andReturn($discoveries);
        $engine->shouldReceive('getLocations')->andReturn($locations);
        $manager = new DiscoveryManager($engine);

        expect($manager->getDiscovery('one'))->toBe($discovery)
            ->and($manager->getDiscoveries())->toBe($discoveries)
            ->and($manager->getLocations())->toBe($locations)
            ->and($manager->getEngine())->toBe($engine);
    });

    it('has no performance statistics when the engine keeps none', function (): void {
        $manager = new DiscoveryManager(Mockery::mock(DiscoveryEngineInterface::class));

        expect($manager->getPerformanceStats())->toBe([]);
    });

    it('reports the performance statistics of an engine that keeps them', function (): void {
        $manager = new DiscoveryManager(managerEngine());

        expect($manager->getPerformanceStats())->toHaveKeys(['context', 'instance_pool', 'static_cache_size']);
    });
});

describe('looking discoveries up', function (): void {
    it('knows which discoveries are registered', function (): void {
        $manager = new DiscoveryManager(managerEngine());
        $manager->addDiscovery('known', new ManagerTestDiscovery);

        expect($manager->hasDiscovery('known'))->toBeTrue()
            ->and($manager->hasDiscovery('unknown'))->toBeFalse();
    });

    it('lets an unknown discovery be reported as such when asked for directly', function (): void {
        $manager = new DiscoveryManager(managerEngine());

        expect(fn (): DiscoveryInterface => $manager->getDiscovery('unknown'))->toThrow(DiscoveryNotFoundException::class);
    });

    it('returns the items a discovery has found', function (): void {
        $discovery = new ManagerTestDiscovery;
        $discovery->getItems()->add(new DiscoveryLocation('App\\', '/app'), 'item');
        $manager = new DiscoveryManager(managerEngine());
        $manager->addDiscovery('known', $discovery);

        expect($manager->getDiscoveredItems('known'))->toBe(['item']);
    });
});

describe('working on one location', function (): void {
    function managerStructure(): DiscoveredClass
    {
        return DiscoveredClass::fromReflection(new ReflectionClass(ManagerTestStructureClass::class));
    }

    it('runs one discovery on a location, leaving the engine own locations alone', function (): void {
        $manager = new DiscoveryManager(managerEngine([managerStructure()]));
        $discovery = new ManagerTestDiscovery;
        $manager->addDiscovery('known', $discovery);
        $manager->addLocation('/engine-location');

        $manager->runSpecificDiscovery('known', new DiscoveryLocation('Tests\\', __DIR__));

        expect($discovery->seen)->toBe(['ManagerTestStructureClass'])
            ->and($discovery->applied)->toBe(1)
            ->and($manager->getLocations())->toHaveCount(1);
    });

    it('does nothing for a discovery that is not registered', function (): void {
        $manager = new DiscoveryManager(managerEngine([managerStructure()]));
        $discovery = new ManagerTestDiscovery;
        $manager->addDiscovery('known', $discovery);

        $result = $manager->runSpecificDiscovery('other', new DiscoveryLocation('Tests\\', __DIR__));

        expect($result)->toBe($manager)
            ->and($discovery->seen)->toBe([]);
    });

    it('discovers every registered type in a location and groups what it found', function (): void {
        $manager = new DiscoveryManager(managerEngine([managerStructure()]));
        $manager->addDiscovery('found', new ManagerTestDiscovery);
        $manager->addDiscovery('empty', new class implements DiscoveryInterface
        {
            use IsDiscovery;

            public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void {}

            public function apply(): void {}
        });

        $results = $manager->discoverAllInLocation(new DiscoveryLocation('Tests\\', __DIR__));

        expect($results)->toBe(['found' => ['ManagerTestStructureClass']]);
    });
});

describe('debug information', function (): void {
    it('counts discoveries and locations, and shows the first three items of each', function (): void {
        $discovery = new ManagerTestDiscovery;
        $location = new DiscoveryLocation('App\\', __DIR__);
        foreach (['a', 'b', 'c', 'd'] as $item) {
            $discovery->getItems()->add($location, $item);
        }
        $manager = new DiscoveryManager(managerEngine());
        $manager->addDiscovery('known', $discovery);
        $manager->addLocation(__DIR__);
        $manager->addLocation(__DIR__.'/missing-'.bin2hex(random_bytes(4)));

        $info = $manager->getDebugInfo();

        expect($info['discoveries_count'])->toBe(1)
            ->and($info['locations_count'])->toBe(2)
            ->and($info['discoveries']['known'])->toBe([
                'class' => ManagerTestDiscovery::class,
                'items_count' => 4,
                'items' => ['a', 'b', 'c'],
            ])
            ->and(array_column($info['locations'], 'exists'))->toBe([true, false]);
    });
});
