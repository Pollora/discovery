# Changelog

All notable changes to `pollora/discovery` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.1] - 2026-11-26

### Fixed

- A cached discovery entry that does not hold discovered structures (written by another version of the package, or truncated) is ignored and the location is scanned again, which also rewrites the entry. It used to be handed to the discoveries as it was, and the first `discover()` call failed with a `TypeError` until the cache was cleared by hand

### Changed

- `ContainerInstanceResolver` throws `InstantiationFailedException` when the container returns something that is not an instance of the requested class or interface; it used to hand that object to the caller

## [0.3.0] - 2026-11-23

### Changed

- **BREAKING**: `DiscoveryManager` is now `final` (and `readonly`, its only property being the engine it was given) (CONV-08). A class that extends it no longer loads, and a test can no longer mock it with Mockery: build it over a `DiscoveryEngineInterface` (mocked or real), which is the one collaborator it has
- `CircularDependencyException` is now `final` (CONV-08). This is a restriction of the API for anyone who extended it: a class that is not an extension point can no longer be subclassed; compose it instead

## [0.2.0] - 2026-11-19

### Changed

- A warm discovery cache is read once per location: `DiscoveryCacheManager` takes the entry from the driver with `get()` right after its own `has()`, instead of going through Spatie's `Discover::get()`, which asked `has()` and `get()` again. On a store whose `has()` unserializes the entry (Laravel's file store), that saves one full read per scanned location and per request. A cold scan is unchanged and still writes the cache once
- A circular dependency and a class that cannot be instantiated throw the named `CircularDependencyException` and `InstantiationFailedException` exceptions instead of a bare `\RuntimeException`. They extend `\RuntimeException`, so a `catch (\RuntimeException)` keeps working, and the messages keep their prefixes (`Circular dependency detected while building …`, `Failed to instantiate class …`); the circular one now says how to break the loop
- `HasInstancePool` no longer imports anything from the infrastructure layer: `setInstancePool()` accepts the new `Domain\Contracts\InstancePoolInterface`, which `InstancePool` implements, so every existing call keeps working. Without a pool, a fallback or `resolve()`, the trait builds the class with `Domain\Services\ReflectionInstanceBuilder`, the code that `ReflectionInstanceResolver` (now a thin subclass of it) used to hold

### Deprecated

- `Domain\Exceptions\CircularDependency`, an alias of `CircularDependencyException` kept for the code written against the first draft of that exception (`catch`, `new`, `instanceof` and `::whileBuilding()` all still work); it goes away in the next major version

### Tests

- PERF-02: counter-based tests (no timing) prove that a warm cache opens, stats and lists nothing in the scanned directory, that a cold or cleared cache does, and how many reads the cache itself costs; `tests/Support/FileAccessProbe.php` is the reusable probe. `php tests/Benchmark/warm-cache.php` measures cold against warm

- The architecture rules (`tests/Arch.php`) run with the unit suite and in CI (`composer test:unit` now runs the `Unit` and `Architecture` suites)

## [0.1.0] - 2026-10-12

A first version for the framework's next release to build on; 1.0.0 comes with that release.

### Added

- First release, extracted from `pollora/framework` (`src/Discovery`) with its history. Namespaces are unchanged: `Pollora\Discovery\*`, and `Pollora\Attributes\SkipDiscovery`
- Runs without Laravel's application: `new DiscoveryEngine()` works with no argument. `InstanceResolverInterface` (`ReflectionInstanceResolver`, `ContainerInstanceResolver` for PSR-11) and `DiscoveryRegistrarInterface` let an environment plug in its container and its discoveries
- `Psr16DiscoverCacheDriver` caches scans in any PSR-16 cache
- Benchmarks with time and memory bounds (`composer test:benchmark`), and a full report (`composer benchmark`)
