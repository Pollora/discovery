<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Services;

use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Pollora\Application\Domain\Contracts\DebugDetectorInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryEngineInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Exceptions\DiscoveryException;
use Pollora\Discovery\Domain\Exceptions\DiscoveryNotFoundException;
use Pollora\Discovery\Domain\Exceptions\InvalidDiscoveryException;
use Pollora\Discovery\Domain\Models\DiscoveryContext;
use Pollora\Discovery\Domain\Models\DiscoveryItems;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Cache\DiscoverCacheDriver;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * Discovery Engine
 *
 * Core engine that orchestrates the discovery process using Spatie's
 * structure discoverer as the foundation while providing a Tempest-inspired API.
 *
 * This engine handles:
 * - Managing discovery locations and discoveries
 * - Coordinating the discovery process across PHP structures and files
 * - Caching discovery results for performance
 * - Applying discovered items through registered discoveries
 */
final class DiscoveryEngine implements DiscoveryEngineInterface
{
    /**
     * Collection of discovery locations
     *
     * @var Collection<int, DiscoveryLocationInterface>
     */
    private Collection $locations;

    /**
     * Collection of registered discoveries
     *
     * @var Collection<string, DiscoveryInterface>
     */
    private Collection $discoveries;

    /**
     * Discovery context for coordinating across discoveries
     */
    private readonly DiscoveryContext $context;

    /**
     * Instance pool for managing class instances
     */
    private readonly InstancePool $instancePool;

    /**
     * Cache manager for Spatie's structure discovery
     */
    private readonly DiscoveryCacheManager $cacheManager;

    /**
     * Create a new discovery engine
     *
     * @param  Container  $container  The service container for dependency injection
     * @param  DebugDetectorInterface  $debugDetector  Debug mode detector
     * @param  ReflectionCacheInterface|null  $reflectionCache  Optional reflection cache
     * @param  InstancePool|null  $instancePool  Optional instance pool
     * @param  LoggerInterface|null  $logger  Optional PSR-3 logger
     * @param  DiscoveryCacheManager|null  $cacheManager  Optional cache manager
     */
    public function __construct(
        private readonly Container $container,
        DebugDetectorInterface $debugDetector,
        ?ReflectionCacheInterface $reflectionCache = null,
        ?InstancePool $instancePool = null,
        private readonly ?LoggerInterface $logger = null,
        ?DiscoveryCacheManager $cacheManager = null
    ) {
        $this->locations = new Collection;
        $this->discoveries = new Collection;

        // Initialize optimized services
        $reflectionCache ??= new ReflectionCache($container);
        $this->instancePool = $instancePool ?? new InstancePool($container);
        $this->context = new DiscoveryContext($reflectionCache);
        $this->cacheManager = $cacheManager ?? new DiscoveryCacheManager($container, $debugDetector);
    }

    /**
     * {@inheritDoc}
     */
    public function addLocation(DiscoveryLocationInterface $location): static
    {
        // Check for duplicate locations (same path)
        $locationPath = realpath($location->getPath()) ?: $location->getPath();

        $isDuplicate = $this->locations->contains(function (DiscoveryLocationInterface $existingLocation) use ($locationPath): bool {
            $existingPath = realpath($existingLocation->getPath()) ?: $existingLocation->getPath();

            return $existingPath === $locationPath;
        });

        if (! $isDuplicate) {
            $this->locations->push($location);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function addLocations(array $locations): static
    {
        foreach ($locations as $location) {
            $this->addLocation($location);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function addDiscovery(string $identifier, string|DiscoveryInterface $discovery): static
    {
        if ($this->discoveries->has($identifier)) {
            throw InvalidDiscoveryException::duplicateIdentifier($identifier);
        }

        $discoveryInstance = $this->resolveDiscovery($discovery);
        $this->discoveries->put($identifier, $discoveryInstance);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function addDiscoveries(array $discoveries): static
    {
        foreach ($discoveries as $identifier => $discovery) {
            $this->addDiscovery($identifier, $discovery);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function discover(): static
    {
        // Discover all structures but don't preload reflection - keep lazy loading
        $allStructures = $this->discoverAllStructures();

        // Process structures with unified approach without eager reflection loading
        $this->processStructuresUnified($allStructures);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function apply(): static
    {
        foreach ($this->discoveries as $discovery) {
            try {
                // Inject instance pool into discoveries that can use it
                $this->injectInstancePoolIfSupported($discovery);

                $discovery->apply();
            } catch (\Throwable $e) {
                throw DiscoveryException::applicationFailed($discovery::class, $e);
            }
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function run(): static
    {
        return $this->discover()->apply();
    }

    /**
     * {@inheritDoc}
     */
    public function getDiscovery(string $identifier): DiscoveryInterface
    {
        if (! $this->discoveries->has($identifier)) {
            throw DiscoveryNotFoundException::withIdentifier($identifier);
        }

        return $this->discoveries->get($identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getDiscoveries(): Collection
    {
        return $this->discoveries;
    }

    /**
     * {@inheritDoc}
     */
    public function getLocations(): Collection
    {
        return $this->locations;
    }

    /**
     * Discover items for a single discovery class
     *
     * @param  DiscoveryInterface  $discovery  The discovery instance
     *
     * @throws DiscoveryException When discovery fails
     */
    private function discoverSingle(DiscoveryInterface $discovery): void
    {
        try {
            // Initialize fresh discovery items
            $discovery->setItems(new DiscoveryItems);

            // Discover PHP structures using Spatie's native cache
            $this->discoverStructures($discovery);
        } catch (\Throwable $throwable) {
            $this->logDiscoveryError($discovery::class, $throwable);
            throw DiscoveryException::discoveryFailed($discovery::class, $throwable);
        }
    }

    /**
     * Discover PHP structures using Spatie's discoverer
     *
     * @param  DiscoveryInterface  $discovery  The discovery instance
     */
    private function discoverStructures(DiscoveryInterface $discovery): void
    {
        $reflectionCache = $this->context->getReflectionCache();

        foreach ($this->locations as $location) {
            $structures = $this->cacheManager->getStructuresForLocation($location, $this->context);

            foreach ($structures as $structure) {
                $discovery->discover($location, $structure, $reflectionCache);
            }
        }
    }

    /**
     * Clear all locations
     */
    public function clearLocations(): static
    {
        $this->locations = new Collection;

        return $this;
    }

    /**
     * Clear persistent discovery cache
     *
     * Only clears persistent cache (Spatie's structure discoverer cache).
     * In-memory caches (reflection, instance pool, static cache) are automatically
     * cleared at the end of the PHP process and don't need manual clearing.
     */
    public function clearCache(): static
    {
        $this->cacheManager->clearCache($this->locations);

        return $this;
    }

    /**
     * Run a specific discovery
     *
     * @param  string  $identifier  The discovery identifier
     * @param  DiscoveryInterface  $discovery  The discovery instance
     */
    public function runDiscovery(string $identifier, DiscoveryInterface $discovery): static
    {
        try {
            $this->discoverSingle($discovery);
            $discovery->apply();
        } catch (\Throwable $throwable) {
            $this->logDiscoveryError($discovery::class, $throwable);
            throw DiscoveryException::discoveryFailed($discovery::class, $throwable);
        }

        return $this;
    }

    /**
     * Clone the engine
     */
    public function __clone(): void
    {
        $this->locations = clone $this->locations;
        $this->discoveries = clone $this->discoveries;
    }

    /**
     * Resolve a discovery instance from class name or instance
     *
     * @param  string|DiscoveryInterface  $discovery  The discovery to resolve
     * @return DiscoveryInterface The resolved discovery instance
     *
     * @throws InvalidDiscoveryException When the discovery is invalid
     */
    private function resolveDiscovery(string|DiscoveryInterface $discovery): DiscoveryInterface
    {
        if ($discovery instanceof DiscoveryInterface) {
            return $discovery;
        }

        if (! class_exists($discovery)) {
            throw InvalidDiscoveryException::invalidClass($discovery, 'Class does not exist');
        }

        if (! is_subclass_of($discovery, DiscoveryInterface::class)) {
            throw InvalidDiscoveryException::missingInterface($discovery, DiscoveryInterface::class);
        }

        try {
            return $this->container->make($discovery);
        } catch (\Throwable $throwable) {
            throw InvalidDiscoveryException::invalidClass($discovery, 'Cannot instantiate: '.$throwable->getMessage());
        }
    }

    /**
     * Discover all structures from all locations using Spatie's discoverer.
     *
     * @return array<array{structure: DiscoveredStructure, location: DiscoveryLocationInterface}> All discovered structures with their locations
     */
    private function discoverAllStructures(): array
    {
        $allStructures = [];

        foreach ($this->locations as $location) {
            $locationStructures = $this->cacheManager->getStructuresForLocation($location, $this->context);

            foreach ($locationStructures as $structure) {
                $allStructures[] = [
                    'structure' => $structure,
                    'location' => $location,
                ];
            }
        }

        return $allStructures;
    }

    /**
     * Process structures using unified approach to minimize redundant operations.
     *
     * @param  array<array{structure: DiscoveredStructure, location: DiscoveryLocationInterface}>  $structures  All discovered structures with their locations
     */
    private function processStructuresUnified(array $structures): void
    {
        // Group structures by class name for batch processing
        $structuresByClass = [];

        foreach ($structures as $entry) {
            $structure = $entry['structure'];
            if ($structure instanceof DiscoveredClass &&
                ! $structure->isAbstract) {
                $className = $structure->namespace.'\\'.$structure->name;
                $structuresByClass[$className] = [
                    'structure' => $structure,
                    'location' => $entry['location'],
                ];
            }
        }

        // Initialize discoveries with fresh items only if they don't have items yet
        foreach ($this->discoveries as $discovery) {
            if (! $discovery->getItems()->isLoaded()) {
                $discovery->setItems(new DiscoveryItems);
            }
        }

        // Process each class once for all applicable discoveries
        foreach ($structuresByClass as $className => $data) {
            $this->processClassForAllDiscoveries(
                $data['structure'],
                $data['location'],
                $className
            );
        }

        $this->context->incrementStat('classes_processed', count($structuresByClass));
    }

    /**
     * Process a single class for all applicable discoveries.
     *
     * @param  DiscoveredClass  $structure  The discovered structure
     * @param  DiscoveryLocationInterface  $location  The discovery location
     * @param  string  $className  The fully qualified class name
     */
    private function processClassForAllDiscoveries(
        DiscoveredClass $structure,
        DiscoveryLocationInterface $location,
        string $className
    ): void {
        try {
            // Get shared reflection data once
            $reflectionCache = $this->context->getReflectionCache();

            // Only get reflection if any discovery might need it
            $reflection = null;
            $classAttributes = null;
            $methodsWithAttributes = null;

            foreach ($this->discoveries as $discoveryId => $discovery) {
                try {
                    // Skip if already processed by this discovery type
                    if ($this->context->isProcessed($className, $discoveryId)) {
                        continue;
                    }

                    // Lazy load reflection data only when needed
                    if (! $reflection instanceof \ReflectionClass && $this->discoveryNeedsReflection($discovery, $structure)) {
                        $reflection = $reflectionCache->getClassReflection($className);
                        $classAttributes = $reflectionCache->getClassAttributes($className);
                        $methodsWithAttributes = $reflectionCache->getMethodsWithAttributes($className);

                        // Store in shared context for other discoveries
                        $this->context->setSharedData($className, 'reflection', $reflection);
                        $this->context->setSharedData($className, 'class_attributes', $classAttributes);
                        $this->context->setSharedData($className, 'methods_with_attributes', $methodsWithAttributes);
                    }

                    // Let discovery process the structure with reflection cache
                    $discovery->discover($location, $structure, $reflectionCache);

                    // Mark as processed
                    $this->context->markProcessed($className, $discoveryId);
                    $this->context->incrementStat('discoveries_executed');

                } catch (\Throwable $e) {
                    $this->context->recordError();
                    $this->logDiscoveryError(sprintf('Discovery %s for class %s', $discoveryId, $className), $e);
                    // Continue with other discoveries
                }
            }

        } catch (\Throwable $throwable) {
            $this->context->recordError();
            $this->logDiscoveryError('Failed to process class '.$className, $throwable);
        }
    }

    /**
     * Check if a discovery needs reflection data.
     *
     * @param  DiscoveryInterface  $discovery  The discovery instance
     * @param  DiscoveredClass  $structure  The discovered structure
     * @return bool True if reflection is needed
     */
    private function discoveryNeedsReflection(DiscoveryInterface $discovery, DiscoveredClass $structure): bool
    {
        // ServiceProviderDiscovery has specific logic for checking class hierarchy
        // Let it handle reflection internally to avoid dependency loading issues
        if ($discovery instanceof ServiceProviderDiscovery) {
            return false;
        }

        // For other discoveries, only load reflection if we need to check attributes
        // and the class seems safe to load (not dependent on external plugins)
        return $structure->attributes === [];
    }

    /**
     * Get the discovery context.
     */
    public function getContext(): DiscoveryContext
    {
        return $this->context;
    }

    /**
     * Get the instance pool.
     */
    public function getInstancePool(): InstancePool
    {
        return $this->instancePool;
    }

    /**
     * Get performance statistics.
     *
     * @return array<string, mixed>
     */
    public function getPerformanceStats(): array
    {
        return [
            'context' => $this->context->getSummary(),
            'instance_pool' => $this->instancePool->getStats(),
            'static_cache_size' => $this->cacheManager->getStaticCacheSize(),
        ];
    }

    /**
     * Inject instance pool into discoveries that support it
     *
     * @param  DiscoveryInterface  $discovery  The discovery to potentially inject into
     */
    private function injectInstancePoolIfSupported(DiscoveryInterface $discovery): void
    {
        // Check if the discovery has a method to accept the instance pool
        if (method_exists($discovery, 'setInstancePool')) {
            $discovery->setInstancePool($this->instancePool);
        }
    }

    /**
     * Get the cache driver instance
     */
    public function getCacheDriver(): ?DiscoverCacheDriver
    {
        return $this->cacheManager->getCacheDriver();
    }

    /**
     * Get the cache manager instance
     */
    public function getCacheManager(): DiscoveryCacheManager
    {
        return $this->cacheManager;
    }

    /**
     * Log discovery errors with consistent format
     *
     * @param  string  $context  The error context
     * @param  \Throwable  $exception  The exception
     */
    private function logDiscoveryError(string $context, \Throwable $exception): void
    {
        $this->logger?->error(sprintf('%s: %s', $context, $exception->getMessage()), [
            'exception' => $exception,
        ]);
    }
}
