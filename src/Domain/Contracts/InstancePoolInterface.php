<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Contracts;

/**
 * Instance Pool Interface
 *
 * Hands out one shared instance per class, so that discoveries do not build
 * the same class twice.
 */
interface InstancePoolInterface
{
    /**
     * Get the pooled instance of a class, building it on first request.
     *
     * @param  string  $className  The fully qualified class name
     * @return object The class instance
     *
     * @throws \Throwable If the class cannot be built
     */
    public function getInstance(string $className): object;
}
