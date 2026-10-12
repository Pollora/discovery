<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Cache;

use Psr\SimpleCache\CacheInterface;
use Spatie\StructureDiscoverer\Cache\DiscoverCacheDriver;

/**
 * Stores discovered structures in any PSR-16 cache.
 *
 * Lets discovery cache its scans wherever a PSR-16 implementation exists,
 * such as WordPress transients or the object cache, without Laravel.
 */
final readonly class Psr16DiscoverCacheDriver implements DiscoverCacheDriver
{
    /**
     * @param  CacheInterface  $cache  The PSR-16 cache to store scans in
     * @param  string  $prefix  Prepended to every key, to keep scans apart from other entries
     * @param  int|null  $ttl  Lifetime in seconds; null keeps entries until cleared
     */
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'pollora-discovery-',
        private ?int $ttl = null,
    ) {}

    public function has(string $id): bool
    {
        return $this->cache->has($this->key($id));
    }

    /**
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        $structures = $this->cache->get($this->key($id));

        return is_array($structures) ? $structures : [];
    }

    /**
     * @param  array<mixed>  $discovered
     */
    public function put(string $id, array $discovered): void
    {
        $this->cache->set($this->key($id), $discovered, $this->ttl);
    }

    public function forget(string $id): void
    {
        $this->cache->delete($this->key($id));
    }

    private function key(string $id): string
    {
        // PSR-16 reserves {}()/\@: in keys.
        return $this->prefix.strtr($id, '{}()/\\@:', '________');
    }
}
