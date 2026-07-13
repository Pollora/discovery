<?php

declare(strict_types=1);

namespace Pollora\Attributes;

use Attribute;

/**
 * Marks a class to be ignored by the discovery engine.
 *
 * When placed on a class, no discovery service will process it —
 * no reflection will be loaded and no attributes will be scanned.
 *
 * Useful for base classes, abstract helpers, or any class that should
 * not be auto-registered by the discovery system.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SkipDiscovery {}
