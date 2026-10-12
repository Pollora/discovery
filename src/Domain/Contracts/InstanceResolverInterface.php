<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Contracts;

/**
 * Instance Resolver Interface
 *
 * Builds the objects discovery needs: discovery classes themselves and the
 * classes they find. A Laravel application resolves them through its
 * container; elsewhere a native resolver builds them by reflection.
 */
interface InstanceResolverInterface
{
    /**
     * Build an instance of the given class.
     *
     * @template T of object
     *
     * @param  class-string<T>  $className  The fully qualified class name
     * @return T The built instance
     *
     * @throws \Throwable If the class cannot be built
     */
    public function make(string $className): object;
}
