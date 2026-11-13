<?php

declare(strict_types=1);

use Pollora\Attributes\SkipDiscovery;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Exceptions\DiscoveryException;
use Pollora\Discovery\Domain\Models\DiscoveryItems;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use Pollora\Discovery\Infrastructure\Services\DiscoveryCacheManager;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\Discovery\Infrastructure\Services\InstancePool;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Cache\StaticDiscoverCacheDriver;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

final class ProcPlainClass {}

final class ProcOtherClass {}

#[SkipDiscovery]
final class ProcSkippedClass {}

#[SkipDiscovery(except: [ProcOnlyDiscovery::class])]
final class ProcSkippedExceptClass {}

/**
 * Records the names of the structures it is shown.
 */
class ProcRecordingDiscovery implements DiscoveryInterface
{
    use IsDiscovery;

    /** @var list<string> */
    public array $seen = [];

    public int $applied = 0;

    public ?object $pool = null;

    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
    {
        $this->seen[] = $structure->name;
        $this->getItems()->add($location, $structure->name);
    }

    public function apply(): void
    {
        $this->applied++;
    }

    public function setInstancePool(object $pool): void
    {
        $this->pool = $pool;
    }
}

final class ProcOnlyDiscovery extends ProcRecordingDiscovery {}

final class ProcThrowingDiscovery extends ProcRecordingDiscovery
{
    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
    {
        throw new RuntimeException('cannot read '.$structure->name);
    }
}

function procStructure(string $class): DiscoveredClass
{
    return DiscoveredClass::fromReflection(new ReflectionClass($class));
}

/**
 * @param  list<DiscoveredStructure>  $structures
 */
function procEngine(array $structures, array $skipClasses = [], array $skipPaths = [], ?LoggerInterface $logger = null): DiscoveryEngine
{
    $cacheManager = Mockery::mock(DiscoveryCacheManager::class);
    $cacheManager->shouldReceive('getStructuresForLocation')->andReturn($structures);

    $engine = new DiscoveryEngine(logger: $logger, cacheManager: $cacheManager, skipClasses: $skipClasses, skipPaths: $skipPaths);
    $engine->addLocation(new DiscoveryLocation('Tests\\', __DIR__));

    return $engine;
}

$all = fn (): array => [procStructure(ProcPlainClass::class), procStructure(ProcSkippedClass::class), procStructure(ProcSkippedExceptClass::class)];

describe('skipping classes', function () use ($all): void {
    it('skips a class marked #[SkipDiscovery], and lets through one that keeps an exception', function () use ($all): void {
        $engine = procEngine($all());
        $discovery = new ProcRecordingDiscovery;
        $only = new ProcOnlyDiscovery;
        $engine->addDiscovery('any', $discovery);
        $engine->addDiscovery('only', $only);

        $engine->discover();

        expect($discovery->seen)->toBe(['ProcPlainClass'])
            ->and($only->seen)->toBe(['ProcPlainClass', 'ProcSkippedExceptClass']);
    });

    it('skips the classes the configuration names', function (): void {
        $engine = procEngine(
            [procStructure(ProcPlainClass::class), procStructure(ProcOtherClass::class)],
            skipClasses: ['\\'.ProcOtherClass::class],
        );
        $discovery = new ProcRecordingDiscovery;
        $engine->addDiscovery('any', $discovery);

        $engine->discover();

        expect($discovery->seen)->toBe(['ProcPlainClass']);
    });

    it('skips the files whose path holds a configured pattern', function (): void {
        $engine = procEngine(
            [procStructure(ProcPlainClass::class), procStructure(ProcOtherClass::class)],
            skipPaths: ['no-such-pattern', basename(__FILE__)],
        );
        $discovery = new ProcRecordingDiscovery;
        $engine->addDiscovery('any', $discovery);

        $engine->discover();

        expect($discovery->seen)->toBe([]);
    });

    it('counts the classes it processed, and the discoveries that handled them', function () use ($all): void {
        $engine = procEngine($all());
        $engine->addDiscovery('any', new ProcRecordingDiscovery);

        $engine->discover();

        expect($engine->getContext()->getStats())->toMatchArray(['classes_processed' => 2, 'discoveries_executed' => 1]);
    });
});

describe('processing', function (): void {
    it('shows a class to a discovery only once, however often the engine discovers', function (): void {
        $engine = procEngine([procStructure(ProcPlainClass::class)]);
        $discovery = new ProcRecordingDiscovery;
        $engine->addDiscovery('any', $discovery);

        $engine->discover();
        $engine->discover();

        expect($discovery->seen)->toBe(['ProcPlainClass']);
    });

    it('keeps the items a discovery already holds', function (): void {
        $engine = procEngine([procStructure(ProcPlainClass::class)]);
        $discovery = new ProcRecordingDiscovery;
        $items = (new DiscoveryItems)->add(new DiscoveryLocation('Old\\', '/old'), 'previous');
        $discovery->setItems($items);
        $engine->addDiscovery('any', $discovery);

        $engine->discover();

        expect($discovery->getItems())->toBe($items)
            ->and($discovery->getItems()->all())->toBe(['previous', 'ProcPlainClass']);
    });

    it('logs a discovery that fails on a class and goes on with the others', function (): void {
        $messages = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->andReturnUsing(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $engine = procEngine([procStructure(ProcPlainClass::class)], logger: $logger);
        $healthy = new ProcRecordingDiscovery;
        $engine->addDiscovery('broken', new ProcThrowingDiscovery);
        $engine->addDiscovery('healthy', $healthy);

        $engine->discover();

        expect($healthy->seen)->toBe(['ProcPlainClass'])
            ->and($messages)->toBe(['Discovery broken for class \ProcPlainClass: cannot read ProcPlainClass'])
            ->and($engine->getContext()->getStats()['errors'])->toBe(1);
    });

    it('survives a failing discovery without a logger', function (): void {
        $engine = procEngine([procStructure(ProcPlainClass::class)]);
        $engine->addDiscovery('broken', new ProcThrowingDiscovery);

        $engine->discover();

        expect($engine->getContext()->getStats()['errors'])->toBe(1);
    });

    it('discovers, then applies, when it runs', function (): void {
        $engine = procEngine([procStructure(ProcPlainClass::class)]);
        $discovery = new ProcRecordingDiscovery;
        $engine->addDiscovery('any', $discovery);

        expect($engine->run())->toBe($engine)
            ->and($discovery->seen)->toBe(['ProcPlainClass'])
            ->and($discovery->applied)->toBe(1);
    });

    it('gives its instance pool to the discoveries that take one, when it applies', function (): void {
        $engine = procEngine([procStructure(ProcPlainClass::class)]);
        $discovery = new ProcRecordingDiscovery;
        $engine->addDiscovery('any', $discovery);

        $engine->run();

        expect($discovery->pool)->toBe($engine->getInstancePool())
            ->and($engine->getInstancePool())->toBeInstanceOf(InstancePool::class);
    });

    it('wraps a failing apply in a discovery exception naming the discovery', function (): void {
        $engine = procEngine([procStructure(ProcPlainClass::class)]);
        $discovery = new class extends ProcRecordingDiscovery
        {
            public function apply(): void
            {
                throw new RuntimeException('cannot register');
            }
        };
        $engine->addDiscovery('any', $discovery);

        try {
            $engine->run();
            $this->fail('The failure was swallowed');
        } catch (DiscoveryException $exception) {
            expect($exception->getMessage())->toBe('Failed to apply discovery for class: '.$discovery::class)
                ->and($exception->getPrevious()->getMessage())->toBe('cannot register');
        }
    });
});

describe('running one discovery', function (): void {
    it('reads every location afresh, then applies', function (): void {
        $engine = procEngine([procStructure(ProcPlainClass::class), procStructure(ProcOtherClass::class)]);
        $discovery = new ProcRecordingDiscovery;
        $discovery->getItems()->add(new DiscoveryLocation('Old\\', '/old'), 'stale');

        expect($engine->runDiscovery('any', $discovery))->toBe($engine)
            ->and($discovery->getItems()->all())->toBe(['ProcPlainClass', 'ProcOtherClass'])
            ->and($discovery->applied)->toBe(1);
    });

    it('logs the failure and names the discovery', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->atLeast()->once();
        $engine = procEngine([procStructure(ProcPlainClass::class)], logger: $logger);
        $discovery = new class extends ProcRecordingDiscovery
        {
            public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
            {
                throw new RuntimeException('cannot read');
            }
        };

        try {
            $engine->runDiscovery('any', $discovery);
            $this->fail('The failure was swallowed');
        } catch (DiscoveryException $exception) {
            expect($exception->getMessage())->toBe('Discovery failed for class: '.$discovery::class);
        }
    });
});

describe('locations and cache', function (): void {
    it('empties its locations', function (): void {
        $engine = procEngine([]);

        expect($engine->clearLocations())->toBe($engine)
            ->and($engine->getLocations())->toHaveCount(0);
    });

    it('does not share its locations or discoveries with its clone', function (): void {
        $engine = procEngine([]);
        $engine->addDiscovery('any', new ProcRecordingDiscovery);
        $clone = clone $engine;

        $clone->clearLocations();
        $clone->addDiscovery('extra', new ProcRecordingDiscovery);

        expect($engine->getLocations())->toHaveCount(1)
            ->and($engine->getDiscoveries()->keys()->all())->toBe(['any']);
    });

    it('clears the persistent cache of its locations', function (): void {
        $cacheManager = Mockery::mock(DiscoveryCacheManager::class);
        $cacheManager->shouldReceive('clearCache')->once();
        $engine = new DiscoveryEngine(cacheManager: $cacheManager);

        expect($engine->clearCache())->toBe($engine);
    });

    it('exposes the cache manager and its driver', function (): void {
        $driver = new StaticDiscoverCacheDriver;
        $manager = new DiscoveryCacheManager($driver);
        $engine = new DiscoveryEngine(cacheManager: $manager);

        expect($engine->getCacheManager())->toBe($manager)
            ->and($engine->getCacheDriver())->toBe($driver)
            ->and((new DiscoveryEngine)->getCacheDriver())->toBeNull();
    });

    it('treats a relative or missing path as the location it is', function (): void {
        $engine = new DiscoveryEngine(cacheManager: Mockery::mock(DiscoveryCacheManager::class));
        $engine->addLocations([
            new DiscoveryLocation('A\\', '/does/not/exist'),
            new DiscoveryLocation('B\\', '/does/not/exist'),
        ]);

        expect($engine->getLocations())->toHaveCount(1);
    });
});
