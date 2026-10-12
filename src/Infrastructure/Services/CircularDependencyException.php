<?php

declare(strict_types=1);

namespace Pollora\Discovery\Infrastructure\Services;

/**
 * Exception thrown when circular dependency is detected during instantiation
 */
class CircularDependencyException extends \RuntimeException {}
