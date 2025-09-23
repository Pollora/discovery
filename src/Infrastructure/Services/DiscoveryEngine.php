<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Services;

use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Pollora\Discovery\Domain\Contracts\DiscoversPathInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryEngineInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Exceptions\DiscoveryException;
use Pollora\Discovery\Domain\Exceptions\DiscoveryNotFoundException;
use Pollora\Discovery\Domain\Exceptions\InvalidDiscoveryException;
use Pollora\Discovery\Domain\Models\DiscoveryItems;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\StructureDiscoverer\Cache\LaravelDiscoverCacheDriver;
use Spatie\StructureDiscoverer\Discover;
use SplFileInfo;

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
     * Create a new discovery engine
     *
     * @param  Container  $container  The service container for dependency injection
     */
    public function __construct(
        private readonly Container $container
    ) {
        $this->locations = new Collection;
        $this->discoveries = new Collection;
    }

    /**
     * {@inheritDoc}
     */
    public function addLocation(DiscoveryLocationInterface $location): static
    {
        $this->locations->push($location);

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
        foreach ($this->discoveries as $discovery) {
            $this->discoverSingle($discovery);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function apply(): static
    {
        foreach ($this->discoveries as $discovery) {
            try {
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

            // Discover file paths if the discovery supports it
            if ($discovery instanceof DiscoversPathInterface) {
                $this->discoverPaths($discovery);
            }
        } catch (\Throwable $e) {
            // Add more detailed error logging
            error_log('Discovery failed for '.$discovery::class.': '.$e->getMessage());
            error_log('Stack trace: '.$e->getTraceAsString());
            throw DiscoveryException::discoveryFailed($discovery::class, $e);
        }
    }

    /**
     * Discover PHP structures using Spatie's discoverer
     *
     * @param  DiscoveryInterface  $discovery  The discovery instance
     */
    private function discoverStructures(DiscoveryInterface $discovery): void
    {
        foreach ($this->locations as $location) {
            // Use Spatie's native caching with a cache identifier based on location and discovery type
            $cacheId = 'discovery_'.$discovery->getIdentifier().'_'.md5($location->getPath());

            $discoveredStructures = Discover::in($location->getPath())
                ->full()
                ->withCache(
                    $cacheId,
                    new LaravelDiscoverCacheDriver
                )
                ->get();

            foreach ($discoveredStructures as $structure) {
                $discovery->discover($location, $structure);
            }
        }
    }

    /**
     * Discover file paths for path-aware discoveries
     *
     * @param  DiscoversPathInterface  $discovery  The path-aware discovery instance
     */
    private function discoverPaths(DiscoversPathInterface $discovery): void
    {
        foreach ($this->locations as $location) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($location->getPath())
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                echo '<pre>';
                var_dump($file);
                echo '</pre>';
                if ($file->isFile()) {
                    $discovery->discoverPath($location, $file->getPathname());
                }
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
        } catch (\Throwable $e) {
            // Add more detailed error logging
            error_log('Discovery failed for '.$discovery::class.': '.$e->getMessage());
            error_log('Stack trace: '.$e->getTraceAsString());
            throw DiscoveryException::discoveryFailed($discovery::class, $e);
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
        } catch (\Throwable $e) {
            throw InvalidDiscoveryException::invalidClass($discovery, "Cannot instantiate: {$e->getMessage()}");
        }
    }
}
