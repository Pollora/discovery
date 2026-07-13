<?php

declare(strict_types=1);

namespace Pollora\Attributes;

use Attribute;

/**
 * Marks a class to be ignored by the discovery engine.
 *
 * Without parameters, the class is completely invisible to all discoveries:
 *
 *     #[SkipDiscovery]
 *     class InternalHelper { ... }
 *
 * With `except`, the class is skipped by all discoveries except the listed ones:
 *
 *     #[SkipDiscovery(except: [ServiceProviderDiscovery::class])]
 *     class MyProvider extends ServiceProvider { ... }
 *
 * @param  array<class-string>  $except  Discovery classes that should still process this class
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SkipDiscovery
{
    /** @var array<class-string> */
    public readonly array $except;

    /**
     * @param  array<class-string>  $except  Discovery classes that should still process this class
     */
    public function __construct(
        array $except = [],
    ) {
        $this->except = $except;
    }
}
