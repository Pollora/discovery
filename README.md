<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/discovery.png" width="100%" alt="Pollora Discovery: find the classes that carry attributes, and hand them to the code that registers them">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/discovery"><img src="https://img.shields.io/packagist/v/pollora/discovery" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/discovery"><img src="https://img.shields.io/packagist/dt/pollora/discovery" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/discovery/actions/workflows/tests.yml"><img src="https://github.com/Pollora/discovery/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/discovery" alt="License"></a>
</p>

Scans directories for PHP classes, reads each class once, and hands it to every discovery that wants it: one finds `#[Action]` methods, another `#[PostType]` classes, a third REST controllers. Each discovery collects what it found, then applies it in one pass. Scans are cached, a class is reflected once whatever the number of discoveries, and each item is applied once per request.

It is the engine behind Pollora's attributes, and it runs without Laravel: in a WordPress plugin, a Bedrock site or any PHP project.

> Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress. In a Pollora project it is already installed and wired: themes, plugins and modules are scanned for you, and your own discoveries are picked up from the container. See [Auto-discovery](https://pollora.dev/core-concepts/auto-discovery/).

## Installation

```bash
composer require pollora/discovery
```

Requires PHP 8.3+. It builds on [`spatie/php-structure-discoverer`](https://github.com/spatie/php-structure-discoverer), which brings `illuminate/collections`; nothing else from Laravel is needed.

## Quick start

A discovery says what it looks for in `discover()` and what to do with it in `apply()`:

```php
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

final class ShortcodeDiscovery implements DiscoveryInterface
{
    use IsDiscovery;

    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
    {
        if (! $structure instanceof DiscoveredClass) {
            return;
        }

        $class = $structure->namespace.'\\'.$structure->name;

        foreach ($reflectionCache->getClassAttributes($class, Shortcode::class) as $attribute) {
            $this->getItems()->add($location, [$attribute->newInstance()->tag, $class]);
        }
    }

    public function apply(): void
    {
        foreach ($this->getItems() as [$tag, $class]) {
            add_shortcode($tag, [new $class, 'render']);
        }
    }
}
```

Point the engine at a directory and run it:

```php
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;

(new DiscoveryEngine)
    ->addLocation(new DiscoveryLocation('MyPlugin\\', __DIR__.'/src'))
    ->addDiscovery('shortcodes', new ShortcodeDiscovery)
    ->run();
```

`run()` is `discover()` then `apply()`. Running again applies only what is new: an item is never applied twice.

## Fitting it to an environment

Every dependency of `DiscoveryEngine` is optional. Without them, it builds classes by reflection, keeps no persistent cache and runs only the discoveries added to it.

| Argument | Contract | Default |
|---|---|---|
| `resolver` | `InstanceResolverInterface`, builds discoveries given by class name and the classes they find | `ReflectionInstanceResolver` (constructor parameters filled from defaults and class types); `ContainerInstanceResolver` wraps any PSR-11 container |
| `registrar` | `DiscoveryRegistrarInterface`, adds the environment's discoveries before each run | none |
| `cacheManager` | `DiscoveryCacheManager`, with a Spatie `DiscoverCacheDriver` | no persistent cache |
| `logger` | PSR-3 | none |
| `skipClasses`, `skipPaths` | classes, or path fragments, never handed to discoveries | none |

### Caching scans

Scanning walks every file of a location, so cache it outside development. `Psr16DiscoverCacheDriver` stores scans in any PSR-16 cache, such as WordPress transients or the object cache; Spatie's own drivers work too.

```php
use Pollora\Discovery\Infrastructure\Cache\Psr16DiscoverCacheDriver;
use Pollora\Discovery\Infrastructure\Services\DiscoveryCacheManager;

$engine = new DiscoveryEngine(
    cacheManager: new DiscoveryCacheManager(
        cacheDriver: new Psr16DiscoverCacheDriver($psr16Cache, ttl: DAY_IN_SECONDS),
        logger: $logger,
    ),
);
```

With `warnOnSlowScans: true` (meant for development, where the cache is off), a location that takes more than 250 ms to scan is logged: it usually holds `node_modules`, `vendor` or a build output.

### Keeping a class out

```php
use Pollora\Attributes\SkipDiscovery;

#[SkipDiscovery]                                  // no discovery sees it
#[SkipDiscovery(except: [HookDiscovery::class])]  // only the ones listed do
final class InternalHelper {}
```

## Public API

The `Pollora\Discovery\Domain\Contracts\*` and `Pollora\Discovery\Domain\Services\*` namespaces are the stable contracts for writing discoveries and follow semantic versioning. `Infrastructure` classes may change in a minor version.

## Testing

```bash
composer test            # unit tests, PHPStan, Pint
composer test:benchmark  # time and memory bounds on generated classes
composer benchmark       # full report: php tests/Benchmark/run.php [--quick|--stress|N…]
```

## License

MIT. See [LICENSE](LICENSE).
