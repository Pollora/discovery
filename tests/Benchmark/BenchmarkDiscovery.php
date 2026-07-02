<?php

declare(strict_types=1);

namespace Tests\Benchmark;

use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryItemsInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Models\DiscoveryItems;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * Benchmark Discovery Implementation
 *
 * A realistic discovery class that mimics the work done by real discoveries
 * (reflection, attribute scanning) without WordPress runtime dependencies.
 * This ensures we measure the actual discovery overhead, not just iterations.
 */
final class BenchmarkDiscovery implements DiscoveryInterface
{
    private DiscoveryItemsInterface $items;

    public function __construct(
        private readonly string $identifier
    ) {
        $this->items = new DiscoveryItems;
    }

    public function discover(
        DiscoveryLocationInterface $location,
        DiscoveredStructure $structure,
        ?ReflectionCacheInterface $reflectionCache = null
    ): void {
        if (!$structure instanceof DiscoveredClass) {
            return;
        }

        if ($structure->isAbstract) {
            return;
        }

        $className = $structure->namespace.'\\'.$structure->name;

        // Simulate realistic discovery work: reflection + attribute scanning
        if ($reflectionCache !== null) {
            try {
                $reflection = $reflectionCache->getClassReflection($className);
                $classAttributes = $reflectionCache->getClassAttributes($className);
                $methods = $reflectionCache->getMethodsWithAttributes($className);

                // Collect items like real discoveries do
                if ($classAttributes !== []) {
                    $this->items->add($location, [
                        'type' => 'class_attribute',
                        'class' => $className,
                        'attributes_count' => count($classAttributes),
                    ]);
                }

                foreach ($methods as $method) {
                    $this->items->add($location, [
                        'type' => 'method_attribute',
                        'class' => $className,
                        'method' => $method->getName(),
                    ]);
                }
            } catch (\Throwable) {
                // Skip unloadable classes, like real discoveries
            }
        }
    }

    public function getItems(): DiscoveryItemsInterface
    {
        return $this->items;
    }

    public function setItems(DiscoveryItemsInterface $items): void
    {
        $this->items = $items;
    }

    public function apply(): void
    {
        // No-op for benchmarking — we measure discovery, not application
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
