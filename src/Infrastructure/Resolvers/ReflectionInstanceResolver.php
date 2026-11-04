<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Resolvers;

use Pollora\Discovery\Domain\Services\ReflectionInstanceBuilder;

/**
 * Builds instances by reflection, for environments without a container.
 *
 * Constructor parameters are filled from their default value, or by
 * building the class they are typed with. A parameter that has neither
 * cannot be filled, and the class is reported as not instantiable.
 */
final class ReflectionInstanceResolver extends ReflectionInstanceBuilder {}
