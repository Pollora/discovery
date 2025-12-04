<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Contracts;

use ReflectionClass;
use ReflectionMethod;

/**
 * Reflection Cache Interface
 *
 * Provides cached access to reflection operations to avoid repetitive
 * expensive reflection calls across multiple discovery services.
 * This interface acts as a centralized cache for class reflection,
 * method attributes, class attributes, and class instances.
 */
interface ReflectionCacheInterface
{
    /**
     * Get cached ReflectionClass instance for a class name.
     *
     * @param  string  $className  The fully qualified class name
     * @return ReflectionClass The cached reflection class
     *
     * @throws \ReflectionException If the class doesn't exist
     */
    public function getClassReflection(string $className): ReflectionClass;

    /**
     * Get cached methods with specific attributes for a class.
     *
     * @param  string  $className  The fully qualified class name
     * @param  string|null  $attributeClass  Optional filter by attribute class
     * @return array<ReflectionMethod> Array of methods with the specified attributes
     */
    public function getMethodsWithAttributes(string $className, ?string $attributeClass = null): array;

    /**
     * Get cached class-level attributes for a class.
     *
     * @param  string  $className  The fully qualified class name
     * @param  string|null  $attributeClass  Optional filter by attribute class
     * @return array<\ReflectionAttribute> Array of class attributes
     */
    public function getClassAttributes(string $className, ?string $attributeClass = null): array;

    /**
     * Get cached class instance from the DI container.
     *
     * @param  string  $className  The fully qualified class name
     * @return object The cached class instance
     *
     * @throws \Throwable If the class cannot be instantiated
     */
    public function getClassInstance(string $className): object;

    /**
     * Check if a class has been processed by reflection cache.
     *
     * @param  string  $className  The fully qualified class name
     * @return bool True if the class is cached
     */
    public function hasClass(string $className): bool;

    /**
     * Preload multiple classes into the cache.
     *
     * @param  array<string>  $classNames  Array of class names to preload
     */
    public function preloadClasses(array $classNames): void;

}