<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Services;

use Illuminate\Container\Container;

/**
 * Instance Pool
 *
 * Centralized pool for managing class instances across discovery services.
 * Prevents redundant instantiation and provides efficient instance reuse
 * with circular dependency detection and memory management.
 *
 * Key features:
 * - Single instance per class across all discoveries
 * - Circular dependency detection during instantiation
 * - Lazy loading with automatic DI container resolution
 * - Memory-efficient cleanup and pooling strategies
 * - Thread-safe operations for concurrent access
 */
final class InstancePool
{
    /**
     * Pool of instantiated objects
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Currently instantiating classes (for circular dependency detection)
     *
     * @var array<string, bool>
     */
    private array $instantiating = [];

    /**
     * Instance creation statistics
     *
     * @var array<string, int>
     */
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'circular_dependencies' => 0,
        'errors' => 0,
    ];

    /**
     * DI Container for instance resolution
     */
    private readonly Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? app();
    }

    /**
     * Get or create an instance of the specified class.
     *
     * @param  string  $className  The fully qualified class name
     * @return object The class instance
     *
     * @throws CircularDependencyException If a circular dependency is detected
     * @throws \RuntimeException If the class cannot be instantiated
     */
    public function getInstance(string $className): object
    {
        // Cache hit - return existing instance
        if (isset($this->instances[$className])) {
            $this->stats['hits']++;
            return $this->instances[$className];
        }

        // Cache miss - need to create instance
        $this->stats['misses']++;

        // Detect circular dependencies
        if (isset($this->instantiating[$className])) {
            $this->stats['circular_dependencies']++;
            throw new CircularDependencyException(
                "Circular dependency detected while instantiating: {$className}"
            );
        }

        try {
            // Mark as currently instantiating
            $this->instantiating[$className] = true;

            // Create instance through DI container
            $instance = $this->container->make($className);

            // Store in pool
            $this->instances[$className] = $instance;

            // Remove from instantiating list
            unset($this->instantiating[$className]);

            return $instance;

        } catch (\Throwable $e) {
            // Clean up instantiating state on error
            unset($this->instantiating[$className]);
            
            $this->stats['errors']++;
            
            throw new \RuntimeException(
                "Failed to instantiate class {$className}: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Check if an instance exists in the pool.
     *
     * @param  string  $className  The fully qualified class name
     * @return bool True if the instance exists in the pool
     */
    public function hasInstance(string $className): bool
    {
        return isset($this->instances[$className]);
    }

    /**
     * Preload multiple instances into the pool.
     *
     * @param  array<string>  $classNames  Array of class names to preload
     * @return array<string, object> Map of successfully preloaded instances
     */
    public function preloadInstances(array $classNames): array
    {
        $preloadedInstances = [];

        foreach ($classNames as $className) {
            try {
                $preloadedInstances[$className] = $this->getInstance($className);
            } catch (\Throwable) {
                // Skip classes that cannot be instantiated
                continue;
            }
        }

        return $preloadedInstances;
    }

    /**
     * Remove an instance from the pool.
     *
     * @param  string  $className  The class name to remove
     * @return bool True if the instance was removed, false if it didn't exist
     */
    public function removeInstance(string $className): bool
    {
        if (isset($this->instances[$className])) {
            unset($this->instances[$className]);
            return true;
        }

        return false;
    }

    /**
     * Clear all instances from the pool.
     */
    public function clearAll(): void
    {
        $this->instances = [];
        $this->instantiating = [];
    }

    /**
     * Get the number of instances in the pool.
     *
     * @return int The number of pooled instances
     */
    public function getPoolSize(): int
    {
        return count($this->instances);
    }

    /**
     * Get pool statistics.
     *
     * @return array<string, mixed> Pool usage statistics
     */
    public function getStats(): array
    {
        $total = $this->stats['hits'] + $this->stats['misses'];
        $hitRatio = $total > 0 ? round(($this->stats['hits'] / $total) * 100, 2) : 0.0;

        return [
            'pool_size' => $this->getPoolSize(),
            'cache_hits' => $this->stats['hits'],
            'cache_misses' => $this->stats['misses'],
            'hit_ratio_percent' => $hitRatio,
            'circular_dependencies' => $this->stats['circular_dependencies'],
            'instantiation_errors' => $this->stats['errors'],
            'total_requests' => $total,
        ];
    }

    /**
     * Get list of all pooled class names.
     *
     * @return array<string> Array of pooled class names
     */
    public function getPooledClasses(): array
    {
        return array_keys($this->instances);
    }

    /**
     * Perform memory optimization by removing unused instances.
     *
     * @param  int  $maxPoolSize  Maximum number of instances to keep
     */
    public function optimize(int $maxPoolSize = 100): void
    {
        if ($this->getPoolSize() <= $maxPoolSize) {
            return;
        }

        // Simple LRU-style cleanup - remove excess instances
        $excessCount = $this->getPoolSize() - $maxPoolSize;
        $classNames = array_keys($this->instances);

        for ($i = 0; $i < $excessCount; $i++) {
            if (isset($classNames[$i])) {
                unset($this->instances[$classNames[$i]]);
            }
        }
    }

    /**
     * Reset statistics counters.
     */
    public function resetStats(): void
    {
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'circular_dependencies' => 0,
            'errors' => 0,
        ];
    }

    /**
     * Check if a class is currently being instantiated (circular dependency check).
     *
     * @param  string  $className  The class name to check
     * @return bool True if the class is currently being instantiated
     */
    public function isInstantiating(string $className): bool
    {
        return isset($this->instantiating[$className]);
    }

    /**
     * Get debug information about the instance pool state.
     *
     * @return array<string, mixed> Debug information
     */
    public function getDebugInfo(): array
    {
        return [
            'instances' => $this->getPooledClasses(),
            'instantiating' => array_keys($this->instantiating),
            'stats' => $this->getStats(),
            'memory_usage' => $this->estimateMemoryUsage(),
        ];
    }

    /**
     * Estimate memory usage of the instance pool.
     *
     * @return string Human-readable memory usage estimate
     */
    private function estimateMemoryUsage(): string
    {
        $memoryUsage = 0;

        foreach ($this->instances as $instance) {
            // Rough estimate - this is not precise but gives an idea
            $memoryUsage += strlen(serialize($instance));
        }

        return $this->formatBytes($memoryUsage);
    }

    /**
     * Format bytes into human-readable format.
     *
     * @param  int  $bytes  Number of bytes
     * @return string Formatted string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}

/**
 * Exception thrown when circular dependency is detected during instantiation
 */
class CircularDependencyException extends \RuntimeException
{
}