<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Models;

use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * Discovery Context
 *
 * Shared context and state management for the discovery process.
 * This class coordinates between different discovery services to avoid
 * redundant processing and enables efficient cross-discovery optimizations.
 *
 * Key responsibilities:
 * - Track processed classes and discovery types
 * - Share reflection cache across discoveries
 * - Coordinate batch processing of structures
 * - Provide unified access to discovery metadata
 */
final class DiscoveryContext
{
    /**
     * Map of processed classes by discovery type
     *
     * @var array<string, array<string, bool>>
     */
    private array $processedClasses = [];

    /**
     * Shared metadata between discoveries
     *
     * @var array<string, array<string, mixed>>
     */
    private array $sharedData = [];

    /**
     * Current processing stats
     *
     * @var array<string, mixed>
     */
    private array $processingStats = [
        'classes_processed' => 0,
        'discoveries_executed' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'errors' => 0,
    ];

    /**
     * Create a new discovery context
     *
     * @param  ReflectionCacheInterface  $reflectionCache  The reflection cache service
     */
    public function __construct(
        private readonly ReflectionCacheInterface $reflectionCache
    ) {}

    /**
     * Mark a class as processed by a specific discovery type.
     *
     * @param  string  $className  The fully qualified class name
     * @param  string  $discoveryType  The discovery type identifier
     */
    public function markProcessed(string $className, string $discoveryType): void
    {
        $this->processedClasses[$className][$discoveryType] = true;
    }

    /**
     * Check if a class has been processed by a specific discovery type.
     *
     * @param  string  $className  The fully qualified class name
     * @param  string  $discoveryType  The discovery type identifier
     * @return bool True if the class has been processed
     */
    public function isProcessed(string $className, string $discoveryType): bool
    {
        return $this->processedClasses[$className][$discoveryType] ?? false;
    }

    /**
     * Check if a class has been processed by any discovery type.
     *
     * @param  string  $className  The fully qualified class name
     * @return bool True if the class has been processed by any discovery
     */
    public function isClassProcessed(string $className): bool
    {
        return isset($this->processedClasses[$className]) && 
               ! empty($this->processedClasses[$className]);
    }

    /**
     * Get the list of discovery types that have processed a class.
     *
     * @param  string  $className  The fully qualified class name
     * @return array<string> Array of discovery type identifiers
     */
    public function getProcessedDiscoveries(string $className): array
    {
        return array_keys($this->processedClasses[$className] ?? []);
    }

    /**
     * Store shared data for a class that can be accessed by other discoveries.
     *
     * @param  string  $className  The fully qualified class name
     * @param  string  $key  The data key
     * @param  mixed  $value  The data value
     */
    public function setSharedData(string $className, string $key, mixed $value): void
    {
        $this->sharedData[$className][$key] = $value;
    }

    /**
     * Get shared data for a class.
     *
     * @param  string  $className  The fully qualified class name
     * @param  string|null  $key  Optional specific key to retrieve
     * @return mixed The shared data value or array of all data
     */
    public function getSharedData(string $className, ?string $key = null): mixed
    {
        if ($key === null) {
            return $this->sharedData[$className] ?? [];
        }

        return $this->sharedData[$className][$key] ?? null;
    }

    /**
     * Check if shared data exists for a class and key.
     *
     * @param  string  $className  The fully qualified class name
     * @param  string  $key  The data key
     * @return bool True if the data exists
     */
    public function hasSharedData(string $className, string $key): bool
    {
        return isset($this->sharedData[$className][$key]);
    }

    /**
     * Get the reflection cache instance.
     *
     * @return ReflectionCacheInterface
     */
    public function getReflectionCache(): ReflectionCacheInterface
    {
        return $this->reflectionCache;
    }

    /**
     * Preload a batch of classes into the reflection cache.
     *
     * @param  array<DiscoveredStructure>  $structures  Array of discovered structures
     */
    public function preloadStructures(array $structures): void
    {
        $classNames = [];

        foreach ($structures as $structure) {
            if ($structure instanceof \Spatie\StructureDiscoverer\Data\DiscoveredClass && 
                ! $structure->isAbstract) {
                $classNames[] = $structure->namespace . '\\' . $structure->name;
            }
        }

        $this->reflectionCache->preloadClasses($classNames);
    }

    /**
     * Increment a processing statistic.
     *
     * @param  string  $stat  The statistic name
     * @param  int  $increment  The increment value
     */
    public function incrementStat(string $stat, int $increment = 1): void
    {
        $this->processingStats[$stat] = ($this->processingStats[$stat] ?? 0) + $increment;
    }

    /**
     * Get processing statistics.
     *
     * @return array<string, mixed> Array of processing statistics
     */
    public function getStats(): array
    {
        return $this->processingStats;
    }

    /**
     * Record a cache hit.
     */
    public function recordCacheHit(): void
    {
        $this->incrementStat('cache_hits');
    }

    /**
     * Record a cache miss.
     */
    public function recordCacheMiss(): void
    {
        $this->incrementStat('cache_misses');
    }

    /**
     * Record a processing error.
     */
    public function recordError(): void
    {
        $this->incrementStat('errors');
    }

    /**
     * Get cache efficiency as a percentage.
     *
     * @return float Cache hit ratio as percentage
     */
    public function getCacheEfficiency(): float
    {
        $hits = $this->processingStats['cache_hits'];
        $misses = $this->processingStats['cache_misses'];
        $total = $hits + $misses;

        return $total > 0 ? round(($hits / $total) * 100, 2) : 0.0;
    }

    /**
     * Reset the discovery context to its initial state.
     */
    public function reset(): void
    {
        $this->processedClasses = [];
        $this->sharedData = [];
        $this->processingStats = [
            'classes_processed' => 0,
            'discoveries_executed' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'errors' => 0,
        ];
        $this->reflectionCache->clearCache();
    }

    /**
     * Get a summary of the discovery context state.
     *
     * @return array<string, mixed> Summary information
     */
    public function getSummary(): array
    {
        $totalClasses = count($this->processedClasses);
        $totalDiscoveries = array_sum(array_map('count', $this->processedClasses));

        return [
            'total_classes' => $totalClasses,
            'total_discovery_executions' => $totalDiscoveries,
            'cache_efficiency' => $this->getCacheEfficiency(),
            'shared_data_entries' => array_sum(array_map('count', $this->sharedData)),
            'stats' => $this->processingStats,
        ];
    }
}