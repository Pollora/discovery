<?php

declare(strict_types=1);

namespace Pollora\Discovery\Tests\Fixtures;

use Pollora\Attributes\SkipDiscovery;
use Pollora\Discovery\Infrastructure\Resolvers\ReflectionInstanceResolver;

/**
 * Namespaced fixtures: the global-namespace classes of the test files cannot
 * tell a fully qualified name from a bare one.
 */
#[SkipDiscovery(except: [\GapOnlyRecorder::class])]
final class NamespacedSkippedExcept {}

final class NamespacedPlain {}

/**
 * A constructor without parameters that builds its own class once more.
 */
final class NestedBuilder
{
    public static int $depth = 0;

    public static ?ReflectionInstanceResolver $resolver = null;

    public ?self $inner = null;

    public function __construct()
    {
        if (self::$depth === 0) {
            self::$depth++;

            try {
                $this->inner = self::$resolver->make(self::class);
            } finally {
                self::$depth--;
            }
        }
    }
}
