<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Services;

use Pollora\Discovery\Infrastructure\Services\InstancePool;

/**
 * Trait HasInstancePool
 *
 * Provides standardized Instance Pool functionality for Discovery classes.
 * This trait eliminates code duplication by providing common instance pool
 * management methods and properties that can be shared across all discoveries.
 */
trait HasInstancePool
{
    /**
     * Optional instance pool for centralized instance management
     */
    private ?InstancePool $instancePool = null;

    /**
     * Set the instance pool for centralized instance management.
     */
    public function setInstancePool(InstancePool $instancePool): void
    {
        $this->instancePool = $instancePool;
    }

    /**
     * Get an instance from the pool if available, otherwise use fallback.
     *
     * @param  string  $className  The class name to instantiate
     * @param  callable|null  $fallback  Optional fallback creation function
     * @return object The class instance
     */
    protected function getInstanceFromPool(string $className, ?callable $fallback = null): object
    {
        if ($this->instancePool !== null) {
            return $this->instancePool->getInstance($className);
        }

        if ($fallback !== null) {
            return $fallback();
        }

        return app($className);
    }

    /**
     * Check if instance pool is available.
     */
    protected function hasInstancePool(): bool
    {
        return $this->instancePool !== null;
    }
}