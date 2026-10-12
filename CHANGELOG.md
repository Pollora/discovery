# Changelog

All notable changes to `pollora/discovery` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- First release, extracted from `pollora/framework` (`src/Discovery`) with its history. Namespaces are unchanged: `Pollora\Discovery\*`, and `Pollora\Attributes\SkipDiscovery`
- Runs without Laravel's application: `new DiscoveryEngine()` works with no argument. `InstanceResolverInterface` (`ReflectionInstanceResolver`, `ContainerInstanceResolver` for PSR-11) and `DiscoveryRegistrarInterface` let an environment plug in its container and its discoveries
- `Psr16DiscoverCacheDriver` caches scans in any PSR-16 cache
- Benchmarks with time and memory bounds (`composer test:benchmark`), and a full report (`composer benchmark`)
