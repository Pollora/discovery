<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Services;

use Illuminate\Container\Container;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Reflection Cache Implementation
 *
 * High-performance cache for reflection operations that eliminates
 * redundant reflection calls across discovery services. Uses in-memory
 * storage with lazy loading and efficient attribute filtering.
 *
 * Key optimizations:
 * - Single ReflectionClass per class across all discoveries
 * - Pre-computed attribute maps for fast lookups
 * - Lazy loading with automatic dependency resolution
 * - Memory-efficient storage with selective caching
 */
final class ReflectionCache implements ReflectionCacheInterface
{
    /**
     * Cache for ReflectionClass instances
     *
     * @var array<string, ReflectionClass>
     */
    private array $classReflections = [];

    /**
     * Cache for class-level attributes
     *
     * @var array<string, array<string, array<\ReflectionAttribute>>>
     */
    private array $classAttributes = [];

    /**
     * Cache for method-level attributes grouped by class and attribute type
     *
     * @var array<string, array<string, array<ReflectionMethod>>>
     */
    private array $methodsWithAttributes = [];

    /**
     * Cache for class instances from DI container
     *
     * @var array<string, object>
     */
    private array $classInstances = [];

    /**
     * Cache for processed classes to avoid reprocessing
     *
     * @var array<string, bool>
     */
    private array $processedClasses = [];

    /**
     * DI Container for instance resolution
     */
    private readonly Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? app();
    }

    /**
     * {@inheritDoc}
     */
    public function getClassReflection(string $className): ReflectionClass
    {
        if (! isset($this->classReflections[$className])) {
            try {
                // Check if class exists and can be autoloaded first
                if (! class_exists($className, true)) {
                    throw new ReflectionException("Class {$className} does not exist or cannot be autoloaded");
                }
                
                $this->classReflections[$className] = new ReflectionClass($className);
                $this->processedClasses[$className] = true;
            } catch (\Throwable $e) {
                // Log the error but mark as processed to avoid retrying
                $this->processedClasses[$className] = true;
                
                throw new ReflectionException(
                    "Failed to create reflection for class {$className}: {$e->getMessage()}",
                    $e->getCode(),
                    $e
                );
            }
        }

        return $this->classReflections[$className];
    }

    /**
     * {@inheritDoc}
     */
    public function getMethodsWithAttributes(string $className, ?string $attributeClass = null): array
    {
        $cacheKey = $attributeClass ?? '*';

        if (! isset($this->methodsWithAttributes[$className][$cacheKey])) {
            $this->buildMethodAttributeCache($className, $attributeClass);
        }

        return $this->methodsWithAttributes[$className][$cacheKey];
    }

    /**
     * {@inheritDoc}
     */
    public function getClassAttributes(string $className, ?string $attributeClass = null): array
    {
        $cacheKey = $attributeClass ?? '*';

        if (! isset($this->classAttributes[$className][$cacheKey])) {
            $this->buildClassAttributeCache($className, $attributeClass);
        }

        return $this->classAttributes[$className][$cacheKey];
    }

    /**
     * {@inheritDoc}
     */
    public function getClassInstance(string $className): object
    {
        if (! isset($this->classInstances[$className])) {
            try {
                // Validate the class can be instantiated before attempting
                $reflection = $this->getClassReflection($className);

                if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                    throw new \InvalidArgumentException(
                        "Cannot instantiate abstract class, interface, or trait: {$className}"
                    );
                }

                if (! $reflection->isInstantiable()) {
                    throw new \InvalidArgumentException("Class {$className} is not instantiable");
                }

                $this->classInstances[$className] = $this->container->make($className);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Failed to instantiate class {$className}: {$e->getMessage()}",
                    $e->getCode(),
                    $e
                );
            }
        }

        return $this->classInstances[$className];
    }

    /**
     * {@inheritDoc}
     */
    public function hasClass(string $className): bool
    {
        return isset($this->processedClasses[$className]);
    }

    /**
     * {@inheritDoc}
     */
    public function preloadClasses(array $classNames): void
    {
        foreach ($classNames as $className) {
            if (! $this->hasClass($className)) {
                try {
                    $this->getClassReflection($className);
                } catch (\Throwable) {
                    // Skip classes that cannot be loaded
                    continue;
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clearCache(): void
    {
        $this->classReflections = [];
        $this->classAttributes = [];
        $this->methodsWithAttributes = [];
        $this->classInstances = [];
        $this->processedClasses = [];
    }

    /**
     * {@inheritDoc}
     */
    public function clearClass(string $className): void
    {
        unset(
            $this->classReflections[$className],
            $this->classAttributes[$className],
            $this->methodsWithAttributes[$className],
            $this->classInstances[$className],
            $this->processedClasses[$className]
        );
    }

    /**
     * Build method attribute cache for a specific class and attribute type.
     *
     * @param  string  $className  The class name to process
     * @param  string|null  $attributeClass  Optional attribute class filter
     */
    private function buildMethodAttributeCache(string $className, ?string $attributeClass = null): void
    {
        $cacheKey = $attributeClass ?? '*';
        $reflection = $this->getClassReflection($className);
        $methodsWithAttributes = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $hasMatchingAttribute = false;

            if ($attributeClass === null) {
                // Get all methods with any attributes
                $hasMatchingAttribute = ! empty($method->getAttributes());
            } else {
                // Get methods with specific attribute class
                $hasMatchingAttribute = ! empty($method->getAttributes($attributeClass));
            }

            if ($hasMatchingAttribute) {
                $methodsWithAttributes[] = $method;
            }
        }

        $this->methodsWithAttributes[$className][$cacheKey] = $methodsWithAttributes;
    }

    /**
     * Build class attribute cache for a specific class and attribute type.
     *
     * @param  string  $className  The class name to process
     * @param  string|null  $attributeClass  Optional attribute class filter
     */
    private function buildClassAttributeCache(string $className, ?string $attributeClass = null): void
    {
        $cacheKey = $attributeClass ?? '*';
        $reflection = $this->getClassReflection($className);

        if ($attributeClass === null) {
            // Get all class attributes
            $attributes = $reflection->getAttributes();
        } else {
            // Get specific class attributes
            $attributes = $reflection->getAttributes($attributeClass);
        }

        $this->classAttributes[$className][$cacheKey] = $attributes;
    }
}