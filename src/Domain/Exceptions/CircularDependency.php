<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Exceptions;

/*
 * @deprecated Use CircularDependencyException. This alias keeps
 * `catch (CircularDependency $e)` and `new CircularDependency` working, and
 * is removed in the next major version.
 */
class_alias(CircularDependencyException::class, 'Pollora\Discovery\Domain\Exceptions\CircularDependency');
