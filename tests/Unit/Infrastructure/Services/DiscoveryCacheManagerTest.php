<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Models\DiscoveryContext;
use Pollora\Discovery\Infrastructure\Services\DiscoveryCacheManager;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Cache\NullDiscoverCacheDriver;
use Spatie\StructureDiscoverer\Cache\StaticDiscoverCacheDriver;

/**
 * A location that costs seconds has to say so.
 *
 * Discovery walks a directory in full, and Symfony's Finder enumerates before
 * it filters by extension, so a `node_modules/` under a location is paid for
 * on every request that misses the cache.
 */
function cacheManagerLocation(string $path): DiscoveryLocationInterface
{
    $location = Mockery::mock(DiscoveryLocationInterface::class);
    $location->shouldReceive('getPath')->andReturn($path);

    return $location;
}

function scanWith(DiscoveryCacheManager $manager, string $path): void
{
    $manager->getStructuresForLocation(cacheManagerLocation($path), new DiscoveryContext(new ReflectionCache));
}

function warnIfSlow(DiscoveryCacheManager $manager, string $path, float $milliseconds, int $structures): void
{
    // Drive the threshold rather than the clock: a scan slow enough to warn
    // would mean creating tens of thousands of files in a test.
    (new ReflectionMethod($manager, 'warnIfSlow'))->invoke($manager, cacheManagerLocation($path), $milliseconds, $structures);
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/pollora-scan-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0o777, true);
    (new ReflectionProperty(DiscoveryCacheManager::class, 'structuresCache'))->setValue(null, []);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

describe('slow scan warning', function (): void {
    it('names the location, its cost and what it found', function (): void {
        $captured = null;
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()
            ->andReturnUsing(function (string $message) use (&$captured): void {
                $captured = $message;
            });

        warnIfSlow(new DiscoveryCacheManager(logger: $logger, warnOnSlowScans: true), '/srv/site/plugins/demo', 1691.0, 3);

        expect($captured)->toContain('/srv/site/plugins/demo')
            ->toContain('1691ms')
            ->toContain('3 structure(s)')
            ->toContain('node_modules');
    });

    it('stays quiet below the threshold', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('warning');

        warnIfSlow(new DiscoveryCacheManager(logger: $logger, warnOnSlowScans: true), '/srv/site/app', 24.6, 10);
    });

    it('stays quiet unless asked to warn', function (): void {
        // With the cache on, the cost is paid once; a warning on every cold
        // cache would be noise in a production log.
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('warning');

        warnIfSlow(new DiscoveryCacheManager(logger: $logger), '/srv/site/plugins/demo', 5000.0, 1);
    });

    it('does not turn a missing logger into a failure', function (): void {
        warnIfSlow(new DiscoveryCacheManager(warnOnSlowScans: true), '/srv/site/plugins/demo', 5000.0, 1);
    })->throwsNoExceptions();

    it('does not turn a failing logger into a failure', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->andThrow(new RuntimeException('disk full'));

        warnIfSlow(new DiscoveryCacheManager(logger: $logger, warnOnSlowScans: true), '/srv/site/plugins/demo', 5000.0, 1);
    })->throwsNoExceptions();
});

describe('persistent cache', function (): void {
    it('is off without a driver, and with the null driver', function (): void {
        expect((new DiscoveryCacheManager)->isCacheEnabled())->toBeFalse()
            ->and((new DiscoveryCacheManager(new NullDiscoverCacheDriver))->isCacheEnabled())->toBeFalse()
            ->and((new DiscoveryCacheManager(new StaticDiscoverCacheDriver))->isCacheEnabled())->toBeTrue();
    });

    it("tells a real scan from this process's own copy", function (): void {
        $manager = new DiscoveryCacheManager;

        scanWith($manager, $this->dir);
        scanWith($manager, $this->dir);

        $scans = $manager->scans();

        expect($scans)->toHaveCount(2)
            ->and($scans[0]['path'])->toBe($this->dir)
            ->and($scans[0]['source'])->toBe('disk')
            ->and($scans[1]['source'])->toBe('memory')
            ->and($scans[1]['milliseconds'])->toBe(0.0);
    });

    it('tells a warm persistent cache from a scan that filled it', function (): void {
        $driver = new StaticDiscoverCacheDriver;

        $first = new DiscoveryCacheManager($driver);
        scanWith($first, $this->dir);

        // A new process starts with an empty copy of its own, and only the
        // persistent cache is left to answer.
        (new ReflectionProperty(DiscoveryCacheManager::class, 'structuresCache'))->setValue(null, []);

        $second = new DiscoveryCacheManager($driver);
        scanWith($second, $this->dir);

        expect($first->scans()[0]['source'])->toBe('disk')
            ->and($second->scans()[0]['source'])->toBe('cache');
    });

    it('names the classes on disk that a cached entry lacks', function (): void {
        file_put_contents($this->dir.'/NewAction.php', "<?php\nnamespace CacheFixture;\nfinal class NewAction {}\n");
        $driver = new StaticDiscoverCacheDriver;
        $driver->put('discovery_'.md5($this->dir), []);

        expect((new DiscoveryCacheManager($driver))->classesMissingFromCache(cacheManagerLocation($this->dir)))
            ->toBe(['CacheFixture\\NewAction']);
    });

    it('has nothing to compare when the location was never cached, or the cache is off', function (): void {
        expect((new DiscoveryCacheManager(new StaticDiscoverCacheDriver))->classesMissingFromCache(cacheManagerLocation($this->dir)))->toBeNull()
            ->and((new DiscoveryCacheManager)->classesMissingFromCache(cacheManagerLocation($this->dir)))->toBeNull();
    });
});
