<?php

declare(strict_types=1);

use Pollora\Discovery\Application\Services\DiscoveryManager;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Exceptions\DiscoveryException;
use Pollora\Discovery\Domain\Exceptions\InstantiationFailedException;
use Pollora\Discovery\Domain\Models\DirectoryLocation;
use Pollora\Discovery\Domain\Models\DiscoveryContext;
use Pollora\Discovery\Domain\Models\DiscoveryItems;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use Pollora\Discovery\Infrastructure\Resolvers\ReflectionInstanceResolver;
use Pollora\Discovery\Infrastructure\Services\DiscoveryCacheManager;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Pollora\Discovery\Tests\Fixtures\NamespacedPlain;
use Pollora\Discovery\Tests\Fixtures\NamespacedSkippedExcept;
use Pollora\Discovery\Tests\Fixtures\NestedBuilder;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Cache\StaticDiscoverCacheDriver;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;
use Spatie\StructureDiscoverer\Discover;

require_once __DIR__.'/Fixtures/NamespacedClasses.php';

abstract class GapAbstract {}

final class GapPlain {}

final class GapSecond {}

final class GapUntyped
{
    public function __construct(public $anything) {}
}

final class GapStringable implements Stringable
{
    public function __toString(): string
    {
        return 'Gap\\Stringable';
    }
}

class GapRecorder implements DiscoveryInterface
{
    use IsDiscovery;

    /** @var list<string> */
    public array $seen = [];

    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
    {
        $this->seen[] = $structure->name;
        $this->getItems()->add($location, $structure->name);
    }

    public function apply(): void {}
}

final class GapOnlyRecorder extends GapRecorder {}

final class GapFailing extends GapRecorder
{
    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
    {
        throw new RuntimeException('cannot read');
    }
}

final class GapFailingApply extends GapRecorder
{
    public function apply(): void
    {
        throw new RuntimeException('cannot apply');
    }
}

final class GapCodedException extends Exception
{
    public function __construct()
    {
        parent::__construct('database down');
        $this->code = 'HY000';
    }
}

function gapStructure(string $class): DiscoveredClass
{
    return DiscoveredClass::fromReflection(new ReflectionClass($class));
}

function gapEngine(array $structures, array $skipPaths = [], ?LoggerInterface $logger = null): DiscoveryEngine
{
    $cacheManager = Mockery::mock(DiscoveryCacheManager::class);
    $cacheManager->shouldReceive('getStructuresForLocation')->andReturn($structures);
    $cacheManager->shouldReceive('getStaticCacheSize')->andReturn(0);
    $cacheManager->shouldReceive('clearCache');

    $engine = new DiscoveryEngine(logger: $logger, cacheManager: $cacheManager, skipPaths: $skipPaths);
    $engine->addLocation(new DiscoveryLocation('Tests\\', __DIR__));

    return $engine;
}

/**
 * @return array{0: LoggerInterface, 1: ArrayObject}
 */
function gapLogger(): array
{
    $entries = new ArrayObject;
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')->andReturnUsing(function (string $message, array $context = []) use ($entries): void {
        $entries[] = ['message' => $message, 'context' => $context];
    });

    return [$logger, $entries];
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/pollora-gap-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0o777, true);
    (new ReflectionProperty(DiscoveryCacheManager::class, 'structuresCache'))->setValue(null, []);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

describe('engine', function (): void {
    it('goes on with the next structure after one that is not discoverable', function (): void {
        $engine = gapEngine([gapStructure(GapAbstract::class), gapStructure(GapPlain::class)]);
        $discovery = new GapRecorder;
        $engine->addDiscovery('any', $discovery);

        $engine->discover();

        expect($discovery->seen)->toBe(['GapPlain']);
    });

    it('shows a class to a discovery added later, after one that already handled it', function (): void {
        $engine = gapEngine([gapStructure(GapPlain::class)]);
        $first = new GapRecorder;
        $second = new GapOnlyRecorder;
        $engine->addDiscovery('first', $first);
        $engine->discover();
        $engine->addDiscovery('second', $second);

        $engine->discover();

        expect($first->seen)->toBe(['GapPlain'])
            ->and($second->seen)->toBe(['GapPlain']);
    });

    it('does not skip a file whose path holds none of the configured patterns', function (): void {
        $engine = gapEngine([gapStructure(GapPlain::class)], skipPaths: ['no-such-pattern']);
        $discovery = new GapRecorder;
        $engine->addDiscovery('any', $discovery);

        $engine->discover();

        expect($discovery->seen)->toBe(['GapPlain']);
    });

    it('reads the exception list of #[SkipDiscovery] on a namespaced class', function (): void {
        $engine = gapEngine([gapStructure(NamespacedPlain::class), gapStructure(NamespacedSkippedExcept::class)]);
        $other = new GapRecorder;
        $only = new GapOnlyRecorder;
        $engine->addDiscovery('other', $other);
        $engine->addDiscovery('only', $only);

        $engine->discover();

        expect($other->seen)->toBe(['NamespacedPlain'])
            ->and($only->seen)->toBe(['NamespacedPlain', 'NamespacedSkippedExcept']);
    });

    it('goes on with the next structure after one the configuration skips', function (): void {
        $engine = new DiscoveryEngine(cacheManager: (function () {
            $cacheManager = Mockery::mock(DiscoveryCacheManager::class);
            $cacheManager->shouldReceive('getStructuresForLocation')->andReturn([gapStructure(GapSecond::class), gapStructure(GapPlain::class)]);

            return $cacheManager;
        })(), skipClasses: ['\\GapSecond']);
        $engine->addLocation(new DiscoveryLocation('Tests\\', __DIR__));
        $discovery = new GapRecorder;
        $engine->addDiscovery('any', $discovery);

        $engine->discover();

        expect($discovery->seen)->toBe(['GapPlain']);
    });

    it('logs a discovery failing on one location twice, with the exception as context', function (): void {
        [$logger, $entries] = gapLogger();
        $engine = gapEngine([gapStructure(GapPlain::class)], logger: $logger);
        $discovery = new GapFailing;
        $engine->addDiscovery('broken', $discovery);

        // Reading failures of a single class are logged by processClassForAllDiscoveries.
        $engine->discover();

        expect($entries)->toHaveCount(1)
            ->and($entries[0]['context']['exception'])->toBeInstanceOf(RuntimeException::class)
            ->and($entries[0]['context']['exception']->getMessage())->toBe('cannot read');
    });

    it('logs a discovery failing while it reads, both where it failed and where it was run', function (): void {
        [$logger, $entries] = gapLogger();
        $engine = gapEngine([gapStructure(GapPlain::class)], logger: $logger);
        $discovery = new class extends GapRecorder
        {
            public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
            {
                throw new RuntimeException('cannot read');
            }
        };

        expect(fn () => $engine->runDiscovery('any', $discovery))->toThrow(DiscoveryException::class);

        expect($entries)->toHaveCount(2)
            ->and($entries[0]['message'])->toBe($discovery::class.': cannot read')
            ->and($entries[1]['message'])->toBe($discovery::class.': Discovery failed for class: '.$discovery::class);
    });

    it('logs a discovery whose application fails', function (): void {
        [$logger, $entries] = gapLogger();
        $engine = gapEngine([gapStructure(GapPlain::class)], logger: $logger);
        $engine->addDiscovery('any', new GapFailingApply);

        expect(fn () => $engine->runDiscovery('any', new GapFailingApply))->toThrow(DiscoveryException::class);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]['message'])->toContain('cannot apply');
    });
});

describe('manager', function (): void {
    it('discovers a location without the locations the engine already has', function (): void {
        $cacheManager = Mockery::mock(DiscoveryCacheManager::class);
        $cacheManager->shouldReceive('getStructuresForLocation')->andReturnUsing(
            fn (DiscoveryLocationInterface $location): array => [gapStructure($location->getPath() === '/engine-location' ? GapSecond::class : GapPlain::class)]
        );
        $manager = new DiscoveryManager(new DiscoveryEngine(cacheManager: $cacheManager));
        $discovery = new GapRecorder;
        $manager->addDiscovery('any', $discovery);
        $manager->addLocation('/engine-location');

        $manager->discoverAllInLocation(new DiscoveryLocation('Tests\\', __DIR__));

        expect($discovery->seen)->toBe(['GapPlain']);
    });

    it('shows empty collections, and each location path, in its debug information', function (): void {
        $manager = new DiscoveryManager(new DiscoveryEngine);

        expect($manager->getDebugInfo())->toBe([
            'discoveries_count' => 0,
            'locations_count' => 0,
            'discoveries' => [],
            'locations' => [],
        ]);

        $manager->addLocation(__DIR__);

        expect($manager->getDebugInfo()['locations'])->toBe([['path' => __DIR__, 'exists' => true]]);
    });
});

describe('cache manager', function (): void {
    it('counts a hit in the context when it answers from memory', function (): void {
        $manager = new DiscoveryCacheManager;
        $location = new DiscoveryLocation('Tests\\', $this->dir);
        $context = new DiscoveryContext(new ReflectionCache);

        $manager->getStructuresForLocation($location, $context);
        $manager->getStructuresForLocation($location, $context);

        expect($context->getStats())->toMatchArray(['cache_hits' => 1, 'cache_misses' => 1]);
    });

    it('words the slow scan warning in full, and rounds the time', function (float $elapsed, string $time): void {
        $messages = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->andReturnUsing(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $manager = new DiscoveryCacheManager(logger: $logger, warnOnSlowScans: true);
        $location = Mockery::mock(DiscoveryLocationInterface::class);
        $location->shouldReceive('getPath')->andReturn('/srv/plugins/demo');

        (new ReflectionMethod($manager, 'warnIfSlow'))->invoke($manager, $location, $elapsed, 3);

        expect($messages)->toBe([
            'Pollora discovery scanned /srv/plugins/demo in '.$time.'ms for 3 structure(s). '
            .'A directory this slow usually holds node_modules, vendor or a build output; '
            .'discovery walks every file in it on each request while the cache is off.',
        ]);
    })->with([
        'rounds down' => [1691.4, '1691'],
        'rounds up' => [1691.6, '1692'],
    ]);

    it('warns exactly at the threshold', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();
        $manager = new DiscoveryCacheManager(logger: $logger, warnOnSlowScans: true);
        $location = Mockery::mock(DiscoveryLocationInterface::class);
        $location->shouldReceive('getPath')->andReturn('/srv');

        (new ReflectionMethod($manager, 'warnIfSlow'))->invoke($manager, $location, 250.0, 1);
    });

    it('compares what is on disk with entries that are not structure objects', function (): void {
        file_put_contents($this->dir.'/Known.php', "<?php\nnamespace GapFixture;\nfinal class Known {}\n");
        $driver = new StaticDiscoverCacheDriver;
        $driver->put('discovery_'.md5($this->dir), [new GapStringable, 'GapFixture\\Known']);
        $location = Mockery::mock(DiscoveryLocationInterface::class);
        $location->shouldReceive('getPath')->andReturn($this->dir);

        expect((new DiscoveryCacheManager($driver))->classesMissingFromCache($location))->toBe([]);
    });

    it('numbers the missing classes from zero', function (): void {
        foreach (['A', 'B', 'C'] as $name) {
            file_put_contents($this->dir.'/'.$name.'.php', "<?php\nnamespace GapFixture;\nfinal class {$name} {}\n");
        }
        $onDisk = array_map(fn ($structure) => $structure->getFcqn(), Discover::in($this->dir)->full()->get());
        $driver = new StaticDiscoverCacheDriver;
        $driver->put('discovery_'.md5($this->dir), [$onDisk[0]]);
        $location = Mockery::mock(DiscoveryLocationInterface::class);
        $location->shouldReceive('getPath')->andReturn($this->dir);

        expect((new DiscoveryCacheManager($driver))->classesMissingFromCache($location))->toBe([$onDisk[1], $onDisk[2]]);
    });
});

describe('reflection cache', function (): void {
    it('lets the autoloader find a class it does not know yet', function (): void {
        $autoloader = static function (string $class): void {
            if ($class === 'GapLazyLoaded') {
                eval('final class GapLazyLoaded {}');
            }
        };
        spl_autoload_register($autoloader);

        try {
            expect((new ReflectionCache)->getClassReflection('GapLazyLoaded')->getName())->toBe('GapLazyLoaded');
        } finally {
            spl_autoload_unregister($autoloader);
        }
    });

    it('keeps the public methods of a class from one call to the next', function (): void {
        $cache = new ReflectionCache;

        expect($cache->getPublicMethods(GapStringable::class))->toBe($cache->getPublicMethods(GapStringable::class))
            ->and($cache->getPublicMethods(GapStringable::class))->toHaveCount(1);
    });

    it('says why an abstract class cannot be instantiated', function (): void {
        // An interface or a trait never gets that far: class_exists() is false for both,
        // so getClassReflection() refuses them first.
        expect(fn (): object => (new ReflectionCache)->getClassInstance(GapAbstract::class))
            ->toThrow(InstantiationFailedException::class, 'Failed to instantiate class GapAbstract: Cannot instantiate abstract class, interface, or trait: GapAbstract');
    });

    it('preloads the classes after one it cannot load', function (): void {
        $cache = new ReflectionCache;

        $cache->preloadClasses(['App\Missing\Thing', GapPlain::class]);

        expect($cache->hasClass(GapPlain::class))->toBeTrue();
    });
});

describe('models and services', function (): void {
    it('preloads the fully qualified names of namespaced classes', function (): void {
        $received = null;
        $cache = Mockery::mock(ReflectionCacheInterface::class);
        $cache->shouldReceive('preloadClasses')->once()->andReturnUsing(function (array $names) use (&$received): void {
            $received = $names;
        });

        (new DiscoveryContext($cache))->preloadStructures([gapStructure(DirectoryLocation::class)]);

        expect($received)->toBe([DirectoryLocation::class]);
    });

    it('keys a directory by the md5 of its path', function (): void {
        expect((new DirectoryLocation('/srv/site'))->getKey())->toBe(md5('/srv/site'));
    });

    it('keeps no items when asked for the vendor ones', function (): void {
        $items = new DiscoveryItems(['location' => ['item']]);

        expect($items->onlyVendor()->all())->toBe([])
            ->and($items->onlyVendor()->isLoaded())->toBeFalse();
    });

    it('keeps the code of the exception it wraps, even when it is a string', function (): void {
        $exception = InstantiationFailedException::forClass('App\Service', new GapCodedException);

        expect($exception->getCode())->toBe(0)
            ->and($exception->getMessage())->toBe('Failed to instantiate class App\Service: database down');
    });

    it('lets a constructor without parameters build its own class', function (): void {
        NestedBuilder::$resolver = new ReflectionInstanceResolver;

        $instance = NestedBuilder::$resolver->make(NestedBuilder::class);

        expect($instance->inner)->toBeInstanceOf(NestedBuilder::class);
    });

    it('fills an untyped parameter with null', function (): void {
        expect((new ReflectionInstanceResolver)->make(GapUntyped::class)->anything)->toBeNull();
    });
});
