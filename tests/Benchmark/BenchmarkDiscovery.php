<?php

declare(strict_types=1);

namespace Tests\Benchmark;

use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryItemsInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Models\DiscoveryItems;
use ReflectionMethod;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * Benchmark Discovery Implementation
 *
 * Mimics the real work done by framework discoveries:
 * - Gets ReflectionClass via cache
 * - Iterates ALL class-level attributes and calls newInstance() on each
 * - Iterates ALL public methods, gets their attributes, calls newInstance()
 * - Collects items per attribute (not per class)
 *
 * This ensures we measure the actual cost of attribute instantiation
 * and reflection traversal, not just class counting.
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

        if ($reflectionCache === null) {
            return;
        }

        try {
            $reflection = $reflectionCache->getClassReflection($className);

            // Process ALL class-level attributes (like PostTypeDiscovery does)
            $classAttributes = $reflection->getAttributes();
            foreach ($classAttributes as $attribute) {
                try {
                    $instance = $attribute->newInstance();
                    $this->items->add($location, [
                        'type' => 'class_attribute',
                        'class' => $className,
                        'attribute' => $attribute->getName(),
                        'instance' => $instance,
                    ]);
                } catch (\Throwable) {
                    // Skip attributes that can't be instantiated (missing classes)
                }
            }

            // Process ALL public methods and their attributes (like HookDiscovery does)
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                $methodAttributes = $method->getAttributes();
                foreach ($methodAttributes as $attribute) {
                    try {
                        $instance = $attribute->newInstance();
                        $this->items->add($location, [
                            'type' => 'method_attribute',
                            'class' => $className,
                            'method' => $method->getName(),
                            'attribute' => $attribute->getName(),
                            'instance' => $instance,
                            'param_count' => $method->getNumberOfParameters(),
                        ]);
                    } catch (\Throwable) {
                        // Skip attributes that can't be instantiated
                    }
                }
            }
        } catch (\Throwable) {
            // Skip unloadable classes, like real discoveries
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
