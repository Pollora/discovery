<?php

declare(strict_types=1);

use Pollora\Discovery\Infrastructure\Cache\Psr16DiscoverCacheDriver;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * An in-memory PSR-16 cache that rejects the characters the standard
 * reserves, as strict implementations do.
 */
function strictPsr16Cache(): CacheInterface
{
    return new class implements CacheInterface
    {
        /** @var array<string, array{value: mixed, ttl: DateInterval|int|null}> */
        public array $entries = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->entries[$this->valid($key)]['value'] ?? $default;
        }

        public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
        {
            $this->entries[$this->valid($key)] = ['value' => $value, 'ttl' => $ttl];

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->entries[$this->valid($key)]);

            return true;
        }

        public function clear(): bool
        {
            $this->entries = [];

            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            return isset($this->entries[$this->valid($key)]);
        }

        private function valid(string $key): string
        {
            if (strpbrk($key, '{}()/\\@:') !== false) {
                throw new class('Reserved character in '.$key) extends Exception implements InvalidArgumentException {};
            }

            return $key;
        }
    };
}

describe('Psr16DiscoverCacheDriver', function (): void {
    it('returns the structures it stored', function (): void {
        $driver = new Psr16DiscoverCacheDriver(strictPsr16Cache());

        $driver->put('discovery_abc', ['App\\Hooks\\Foo']);

        expect($driver->has('discovery_abc'))->toBeTrue()
            ->and($driver->get('discovery_abc'))->toBe(['App\\Hooks\\Foo']);
    });

    it('reports a missing entry as absent and empty', function (): void {
        $driver = new Psr16DiscoverCacheDriver(strictPsr16Cache());

        expect($driver->has('discovery_missing'))->toBeFalse()
            ->and($driver->get('discovery_missing'))->toBe([]);
    });

    it('forgets an entry', function (): void {
        $driver = new Psr16DiscoverCacheDriver(strictPsr16Cache());
        $driver->put('discovery_abc', ['App\\Hooks\\Foo']);

        $driver->forget('discovery_abc');

        expect($driver->has('discovery_abc'))->toBeFalse();
    });

    it('accepts ids holding characters PSR-16 reserves', function (): void {
        $driver = new Psr16DiscoverCacheDriver(strictPsr16Cache());

        $driver->put('App\\Hooks:{id}', ['App\\Hooks\\Foo']);

        expect($driver->get('App\\Hooks:{id}'))->toBe(['App\\Hooks\\Foo']);
    });

    it('stores under its prefix, with its lifetime', function (): void {
        $cache = strictPsr16Cache();
        $driver = new Psr16DiscoverCacheDriver($cache, prefix: 'site-', ttl: 3600);

        $driver->put('discovery_abc', []);

        expect($cache->entries)->toBe(['site-discovery_abc' => ['value' => [], 'ttl' => 3600]]);
    });
});
