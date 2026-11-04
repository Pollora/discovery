# Changelog

All notable changes to `pollora/discovery` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- A circular dependency and a class that cannot be instantiated throw the named `CircularDependencyException` and `InstantiationFailedException` exceptions instead of a bare `\RuntimeException`. They extend `\RuntimeException`, so a `catch (\RuntimeException)` keeps working, and the messages keep their prefixes (`Circular dependency detected while building …`, `Failed to instantiate class …`); the circular one now says how to break the loop
- `HasInstancePool` no longer imports anything from the infrastructure layer: `setInstancePool()` accepts the new `Domain\Contracts\InstancePoolInterface`, which `InstancePool` implements, so every existing call keeps working. Without a pool, a fallback or `resolve()`, the trait builds the class with `Domain\Services\ReflectionInstanceBuilder`, the code that `ReflectionInstanceResolver` (now a thin subclass of it) used to hold

### Deprecated

- `Domain\Exceptions\CircularDependency`, an alias of `CircularDependencyException` kept for the code written against the first draft of that exception (`catch`, `new`, `instanceof` and `::whileBuilding()` all still work); it goes away in the next major version

### Tests

- The architecture rules (`tests/Arch.php`) run with the unit suite and in CI (`composer test:unit` now runs the `Unit` and `Architecture` suites)

## [0.1.0] - 2026-10-12

A first version for the framework's next release to build on; 1.0.0 comes with that release.

### Added

- First release, extracted from `pollora/framework` (`src/Discovery`) with its history. Namespaces are unchanged: `Pollora\Discovery\*`, and `Pollora\Attributes\SkipDiscovery`
- Runs without Laravel's application: `new DiscoveryEngine()` works with no argument. `InstanceResolverInterface` (`ReflectionInstanceResolver`, `ContainerInstanceResolver` for PSR-11) and `DiscoveryRegistrarInterface` let an environment plug in its container and its discoveries
- `Psr16DiscoverCacheDriver` caches scans in any PSR-16 cache
- Benchmarks with time and memory bounds (`composer test:benchmark`), and a full report (`composer benchmark`)
