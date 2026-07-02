<?php

declare(strict_types=1);

namespace Tests\Benchmark\Fixtures;

/**
 * Generates PHP class files with Pollora attributes for discovery benchmarking.
 *
 * Creates realistic class distributions:
 * - 40% Hook classes (Action/Filter attributes on methods)
 * - 20% ServiceProvider classes
 * - 15% PostType classes (class-level attribute)
 * - 10% REST controller classes
 * - 10% Schedule classes
 * - 5% Plain classes (no attributes — noise)
 */
final class FixtureGenerator
{
    private string $basePath;

    private string $namespace;

    public function __construct(string $basePath, string $namespace = 'Tests\\Benchmark\\Generated')
    {
        $this->basePath = $basePath;
        $this->namespace = $namespace;
    }

    /**
     * Generate a set of fixture classes.
     *
     * @param int $count Number of classes to generate
     * @return array{path: string, namespace: string, count: int, distribution: array<string, int>}
     */
    public function generate(int $count): array
    {
        $this->ensureDirectory($this->basePath);

        $distribution = $this->calculateDistribution($count);
        $index = 0;

        foreach ($distribution as $type => $typeCount) {
            for ($i = 0; $i < $typeCount; $i++) {
                $this->generateClass($type, $index);
                $index++;
            }
        }

        return [
            'path' => $this->basePath,
            'namespace' => $this->namespace,
            'count' => $index,
            'distribution' => $distribution,
        ];
    }

    /**
     * Clean up generated fixtures.
     */
    public function cleanup(): void
    {
        if (is_dir($this->basePath)) {
            $this->removeDirectory($this->basePath);
        }
    }

    private function calculateDistribution(int $count): array
    {
        $hooks = (int) round($count * 0.40);
        $providers = (int) round($count * 0.20);
        $postTypes = (int) round($count * 0.15);
        $rest = (int) round($count * 0.10);
        $schedule = (int) round($count * 0.10);
        $plain = $count - $hooks - $providers - $postTypes - $rest - $schedule;

        return [
            'hook' => $hooks,
            'provider' => $providers,
            'post_type' => $postTypes,
            'rest' => $rest,
            'schedule' => $schedule,
            'plain' => max(0, $plain),
        ];
    }

    private function generateClass(string $type, int $index): void
    {
        $className = $this->classNameForType($type, $index);
        $content = match ($type) {
            'hook' => $this->generateHookClass($className, $index),
            'provider' => $this->generateProviderClass($className),
            'post_type' => $this->generatePostTypeClass($className, $index),
            'rest' => $this->generateRestClass($className, $index),
            'schedule' => $this->generateScheduleClass($className, $index),
            'plain' => $this->generatePlainClass($className),
        };

        file_put_contents($this->basePath.'/'.$className.'.php', $content);
    }

    private function classNameForType(string $type, int $index): string
    {
        return match ($type) {
            'hook' => "BenchHook{$index}",
            'provider' => "BenchProvider{$index}",
            'post_type' => "BenchPostType{$index}",
            'rest' => "BenchRestController{$index}",
            'schedule' => "BenchSchedule{$index}",
            'plain' => "BenchPlain{$index}",
        };
    }

    private function generateHookClass(string $className, int $index): string
    {
        // Each hook class has 2-5 methods with Action/Filter attributes
        $methodCount = ($index % 4) + 2;
        $methods = '';

        for ($m = 0; $m < $methodCount; $m++) {
            $hookName = "bench_hook_{$index}_{$m}";
            $attr = $m % 3 === 0 ? 'Filter' : 'Action';
            $priority = ($m * 5) + 10;
            $methods .= <<<PHP

                #[\\Pollora\\Attributes\\{$attr}(hook: '{$hookName}', priority: {$priority})]
                public function handle{$m}(mixed \$value = null): mixed
                {
                    return \$value;
                }

            PHP;
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            class {$className}
            {
            {$methods}
            }

            PHP;
    }

    private function generateProviderClass(string $className): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            use Illuminate\Support\ServiceProvider;

            class {$className} extends ServiceProvider
            {
                public function register(): void
                {
                    // Benchmark provider
                }

                public function boot(): void
                {
                    // Benchmark provider boot
                }
            }

            PHP;
    }

    private function generatePostTypeClass(string $className, int $index): string
    {
        $slug = "bench_cpt_{$index}";

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            use Pollora\\Attributes\\PostType;

            #[PostType(slug: '{$slug}', singular: 'Bench {$index}', plural: 'Benches {$index}')]
            class {$className}
            {
                public function getSlug(): string
                {
                    return '{$slug}';
                }
            }

            PHP;
    }

    private function generateRestClass(string $className, int $index): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            use Pollora\\Attributes\\WpRestRoute;

            #[WpRestRoute(namespace: 'bench/v1', route: '/items-{$index}')]
            class {$className}
            {
                public function index(): array
                {
                    return [];
                }

                public function show(int \$id): array
                {
                    return ['id' => \$id];
                }
            }

            PHP;
    }

    private function generateScheduleClass(string $className, int $index): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            use Pollora\\Attributes\\Schedule;

            class {$className}
            {
                #[Schedule(recurrence: 'hourly', hook: 'bench_cron_{$index}')]
                public function run(): void
                {
                    // Benchmark scheduled task
                }
            }

            PHP;
    }

    private function generatePlainClass(string $className): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            class {$className}
            {
                public function doSomething(): string
                {
                    return 'plain';
                }
            }

            PHP;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
