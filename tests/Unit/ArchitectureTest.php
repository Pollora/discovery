<?php

declare(strict_types=1);

/**
 * Discovery runs without Laravel's application: in a WordPress plugin, a
 * Bedrock site or any PHP project. Nothing here may reach for the container,
 * the configuration or a facade.
 */
arch('discovery stays free of the Laravel application')
    ->expect(['Pollora\Discovery', 'Pollora\Attributes'])
    ->toOnlyUse([
        'Pollora\Discovery',
        'Pollora\Attributes',
        'Spatie\StructureDiscoverer',
        'Psr\Log',
        'Psr\SimpleCache',
        'Psr\Container',
        'Illuminate\Support\Collection',
        // HasInstancePool falls back to Laravel's resolve() only when it is defined.
        'resolve',
    ]);
