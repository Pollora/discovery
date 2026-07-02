<?php

declare(strict_types=1);

namespace Tests\Benchmark\Fixtures;

/**
 * Generates PHP class files with realistic Pollora attribute complexity for benchmarking.
 *
 * Distribution:
 * - 30% Hook classes (multiple Action/Filter per method, repeatable attributes)
 * - 15% ServiceProvider classes
 * - 15% PostType classes (stacked class-level attributes: PostType + Supports + MenuIcon + ...)
 * - 10% REST controller classes (class attribute + Method per endpoint)
 * - 10% Schedule classes (multiple scheduled methods)
 * - 10% Mixed classes (PostType + hooks, REST + hooks — realistic combos)
 * - 10% Plain classes (no attributes — noise for scanning)
 *
 * Complexity levels per type:
 * - PostType: 3-8 class-level attributes per class
 * - Hook: 2-6 methods, 1-3 attributes per method (IS_REPEATABLE)
 * - REST: 1 class attr + 2-5 methods with Method attr
 * - Schedule: 1-4 scheduled methods per class
 * - Mixed: combines patterns from multiple types
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
     * @param  int  $count  Number of classes to generate
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
     * Generate a multi-location structure simulating a real Pollora project.
     *
     * Creates N modules, each with a DDD-like directory structure:
     *   ModuleName/
     *     app/
     *       PostTypes/
     *       Hooks/
     *       Http/Controllers/
     *       Providers/
     *       Jobs/
     *       Models/
     *
     * Each module is a separate discovery location (like nwidart modules).
     *
     * @param  int  $totalClasses  Total number of classes across all modules
     * @param  int  $moduleCount  Number of modules to create
     * @return array{locations: array<array{path: string, namespace: string}>, total_classes: int, modules: int, dirs_created: int}
     */
    public function generateMultiLocation(int $totalClasses, int $moduleCount): array
    {
        $this->ensureDirectory($this->basePath);

        $classesPerModule = (int) ceil($totalClasses / $moduleCount);
        $locations = [];
        $totalGenerated = 0;
        $dirsCreated = 0;
        $remaining = $totalClasses;

        for ($mod = 0; $mod < $moduleCount; $mod++) {
            $moduleName = 'Module'.chr(65 + ($mod % 26)).($mod >= 26 ? $mod : '');
            $modulePath = $this->basePath.'/'.$moduleName.'/app';
            $moduleNamespace = $this->namespace.'\\'.$moduleName.'\\App';
            $classCount = min($classesPerModule, $remaining);

            if ($classCount <= 0) {
                break;
            }

            // DDD subdirectories
            $subdirs = [
                'PostTypes' => 'post_type',
                'Hooks' => 'hook',
                'Http/Controllers' => 'rest',
                'Providers' => 'provider',
                'Jobs' => 'schedule',
                'Models' => 'plain',
                'Services' => 'mixed',
            ];

            $perSubdir = (int) ceil($classCount / count($subdirs));
            $moduleGenerated = 0;

            foreach ($subdirs as $subdir => $type) {
                $subdirPath = $modulePath.'/'.$subdir;
                $subdirNamespace = $moduleNamespace.'\\'.str_replace('/', '\\', $subdir);

                $subdirCount = min($perSubdir, $classCount - $moduleGenerated);
                if ($subdirCount <= 0) {
                    break;
                }

                $this->ensureDirectory($subdirPath);
                $dirsCreated++;

                for ($i = 0; $i < $subdirCount; $i++) {
                    $globalIndex = $totalGenerated + $moduleGenerated + $i;
                    $className = $this->classNameForType($type, $globalIndex);
                    $content = $this->generateClassContent($type, $className, $globalIndex, $subdirNamespace);
                    file_put_contents($subdirPath.'/'.$className.'.php', $content);
                }

                $moduleGenerated += $subdirCount;
            }

            $locations[] = [
                'path' => $modulePath,
                'namespace' => $moduleNamespace,
            ];

            $totalGenerated += $moduleGenerated;
            $remaining -= $moduleGenerated;
        }

        return [
            'locations' => $locations,
            'total_classes' => $totalGenerated,
            'modules' => count($locations),
            'dirs_created' => $dirsCreated,
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
        $hooks = (int) round($count * 0.30);
        $providers = (int) round($count * 0.15);
        $postTypes = (int) round($count * 0.15);
        $rest = (int) round($count * 0.10);
        $schedule = (int) round($count * 0.10);
        $mixed = (int) round($count * 0.10);
        $plain = $count - $hooks - $providers - $postTypes - $rest - $schedule - $mixed;

        return [
            'hook' => $hooks,
            'provider' => $providers,
            'post_type' => $postTypes,
            'rest' => $rest,
            'schedule' => $schedule,
            'mixed' => $mixed,
            'plain' => max(0, $plain),
        ];
    }

    private function generateClass(string $type, int $index): void
    {
        $className = $this->classNameForType($type, $index);
        $content = $this->generateClassContent($type, $className, $index, $this->namespace);

        file_put_contents($this->basePath.'/'.$className.'.php', $content);
    }

    private function generateClassContent(string $type, string $className, int $index, string $namespace): string
    {
        return match ($type) {
            'hook' => $this->generateHookClass($className, $index, $namespace),
            'provider' => $this->generateProviderClass($className, $namespace),
            'post_type' => $this->generatePostTypeClass($className, $index, $namespace),
            'rest' => $this->generateRestClass($className, $index, $namespace),
            'schedule' => $this->generateScheduleClass($className, $index, $namespace),
            'mixed' => $this->generateMixedClass($className, $index, $namespace),
            'plain' => $this->generatePlainClass($className, $namespace),
        };
    }

    private function classNameForType(string $type, int $index): string
    {
        return match ($type) {
            'hook' => "BenchHook{$index}",
            'provider' => "BenchProvider{$index}",
            'post_type' => "BenchPostType{$index}",
            'rest' => "BenchRestController{$index}",
            'schedule' => "BenchSchedule{$index}",
            'mixed' => "BenchMixed{$index}",
            'plain' => "BenchPlain{$index}",
        };
    }

    /**
     * Hook class: 2-6 methods, each with 1-3 repeatable Action/Filter attributes.
     */
    private function generateHookClass(string $className, int $index, ?string $namespace = null): string
    {
        $ns = $namespace ?? $this->namespace;
        $methodCount = ($index % 5) + 2; // 2 to 6 methods
        $methods = '';

        for ($m = 0; $m < $methodCount; $m++) {
            $attrCount = ($m % 3) + 1; // 1 to 3 attributes per method
            $attrs = '';

            for ($a = 0; $a < $attrCount; $a++) {
                $hookName = "bench_hook_{$index}_{$m}_{$a}";
                $attr = ($m + $a) % 3 === 0 ? 'Filter' : 'Action';
                $priority = (($m * 3) + $a) * 5 + 10;
                $attrs .= "    #[\\Pollora\\Attributes\\{$attr}(hook: '{$hookName}', priority: {$priority})]\n";
            }

            $methods .= <<<PHP

            {$attrs}    public function handle{$m}(mixed \$value = null): mixed
                {
                    return \$value;
                }

            PHP;
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            class {$className}
            {
            {$methods}
            }

            PHP;
    }

    private function generateProviderClass(string $className, ?string $namespace = null): string
    {
        $ns = $namespace ?? $this->namespace;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

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

    /**
     * PostType class: stacked class-level attributes (3-8 per class).
     * Mirrors real usage: #[PostType] + #[Supports] + #[MenuIcon] + #[HasArchive] + ...
     */
    private function generatePostTypeClass(string $className, int $index, ?string $namespace = null): string
    {
        $ns = $namespace ?? $this->namespace;
        $slug = "bench_cpt_{$index}";

        // Pool of class-level PostType sub-attributes
        $subAttributes = [
            "#[\\Pollora\\Attributes\\PostType\\Supports(features: ['title', 'editor', 'thumbnail', 'excerpt', 'comments'])]",
            "#[\\Pollora\\Attributes\\PostType\\MenuIcon(value: 'dashicons-admin-post')]",
            '#[\\Pollora\\Attributes\\PostType\\HasArchive(value: true)]',
            '#[\\Pollora\\Attributes\\PostType\\ShowInRest(value: true)]',
            '#[\\Pollora\\Attributes\\PostType\\ShowUI(value: true)]',
            '#[\\Pollora\\Attributes\\PostType\\Hierarchical(value: false)]',
            '#[\\Pollora\\Attributes\\PostType\\PublicPostType(value: true)]',
            '#[\\Pollora\\Attributes\\PostType\\MenuPosition(value: 25)]',
            '#[\\Pollora\\Attributes\\PostType\\ExcludeFromSearch(value: false)]',
            '#[\\Pollora\\Attributes\\PostType\\PubliclyQueryable(value: true)]',
            '#[\\Pollora\\Attributes\\PostType\\CanExport(value: true)]',
            '#[\\Pollora\\Attributes\\PostType\\DeleteWithUser(value: false)]',
        ];

        // Pick 3-8 sub-attributes based on index
        $subAttrCount = ($index % 6) + 3;
        $selectedAttrs = array_slice($subAttributes, 0, $subAttrCount);
        $attrBlock = implode("\n", $selectedAttrs);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use Pollora\\Attributes\\PostType;

            #[PostType(slug: '{$slug}', singular: 'Bench {$index}', plural: 'Benches {$index}')]
            {$attrBlock}
            class {$className}
            {
                public function getSlug(): string
                {
                    return '{$slug}';
                }

                public function getLabel(): string
                {
                    return 'Bench {$index}';
                }
            }

            PHP;
    }

    /**
     * REST controller class: class-level WpRestRoute + 2-5 methods with Method attribute.
     */
    private function generateRestClass(string $className, int $index, ?string $namespace = null): string
    {
        $ns = $namespace ?? $this->namespace;
        $methodCount = ($index % 4) + 2; // 2 to 5 methods
        $methods = '';

        $httpMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];

        for ($m = 0; $m < $methodCount; $m++) {
            $httpMethod = $httpMethods[$m % count($httpMethods)];
            $methodName = match ($httpMethod) {
                'GET' => $m === 0 ? 'index' : 'show',
                'POST' => 'store',
                'PUT' => 'update',
                'DELETE' => 'destroy',
                'PATCH' => 'patch',
            };
            // Avoid duplicate method names
            $methodName = $methodName.($m > 4 ? $m : '');

            $methods .= <<<PHP

                #[\\Pollora\\Attributes\\WpRestRoute\\Method(methods: '{$httpMethod}')]
                public function {$methodName}(int \$id = 0): array
                {
                    return ['id' => \$id, 'method' => '{$httpMethod}'];
                }

            PHP;
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use Pollora\\Attributes\\WpRestRoute;

            #[WpRestRoute(namespace: 'bench/v1', route: '/items-{$index}/(?P<id>\d+)')]
            class {$className}
            {
            {$methods}
            }

            PHP;
    }

    /**
     * Schedule class: 1-4 methods each with a Schedule attribute.
     */
    private function generateScheduleClass(string $className, int $index, ?string $namespace = null): string
    {
        $ns = $namespace ?? $this->namespace;
        $methodCount = ($index % 4) + 1; // 1 to 4 methods
        $recurrences = ['hourly', 'daily', 'twicedaily', 'weekly'];
        $methods = '';

        for ($m = 0; $m < $methodCount; $m++) {
            $recurrence = $recurrences[$m % count($recurrences)];
            $hookName = "bench_cron_{$index}_{$m}";
            $methods .= <<<PHP

                #[\\Pollora\\Attributes\\Schedule(recurrence: '{$recurrence}', hook: '{$hookName}')]
                public function task{$m}(): void
                {
                    // Benchmark scheduled task {$m}
                }

            PHP;
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            class {$className}
            {
            {$methods}
            }

            PHP;
    }

    /**
     * Mixed class: combines PostType (class attrs) + Hook methods (method attrs).
     * This is the most realistic and complex scenario — a post type class
     * that also registers hooks for admin columns, meta boxes, etc.
     */
    private function generateMixedClass(string $className, int $index, ?string $namespace = null): string
    {
        $ns = $namespace ?? $this->namespace;
        $slug = "bench_mixed_{$index}";
        $variant = $index % 3;

        return match ($variant) {
            0 => $this->generatePostTypeWithHooks($className, $index, $slug, $ns),
            1 => $this->generateRestWithHooksAndSchedule($className, $index, $ns),
            2 => $this->generateHeavyMixed($className, $index, $slug, $ns),
        };
    }

    private function generatePostTypeWithHooks(string $className, int $index, string $slug, ?string $ns = null): string
    {
        $ns ??= $this->namespace;
        $hookCount = ($index % 3) + 2; // 2-4 hook methods
        $methods = '';

        for ($m = 0; $m < $hookCount; $m++) {
            $hookName = "bench_mixed_hook_{$index}_{$m}";
            $methods .= <<<PHP

                #[\\Pollora\\Attributes\\Action(hook: '{$hookName}', priority: 10)]
                public function onHook{$m}(): void
                {
                    // Mixed hook handler
                }

            PHP;
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use Pollora\\Attributes\\PostType;

            #[PostType(slug: '{$slug}', singular: 'Mixed {$index}', plural: 'Mixeds {$index}')]
            #[\\Pollora\\Attributes\\PostType\\Supports(features: ['title', 'editor', 'thumbnail'])]
            #[\\Pollora\\Attributes\\PostType\\ShowInRest(value: true)]
            #[\\Pollora\\Attributes\\PostType\\HasArchive(value: true)]
            #[\\Pollora\\Attributes\\PostType\\MenuIcon(value: 'dashicons-portfolio')]
            class {$className}
            {
            {$methods}
            }

            PHP;
    }

    private function generateRestWithHooksAndSchedule(string $className, int $index, ?string $ns = null): string
    {
        $ns ??= $this->namespace;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use Pollora\\Attributes\\WpRestRoute;

            #[WpRestRoute(namespace: 'bench/v1', route: '/mixed-{$index}')]
            class {$className}
            {
                #[\\Pollora\\Attributes\\WpRestRoute\\Method(methods: 'GET')]
                public function index(): array
                {
                    return [];
                }

                #[\\Pollora\\Attributes\\WpRestRoute\\Method(methods: 'POST')]
                public function store(): array
                {
                    return [];
                }

                #[\\Pollora\\Attributes\\WpRestRoute\\Method(methods: 'DELETE')]
                public function destroy(int \$id): array
                {
                    return ['deleted' => \$id];
                }

                #[\\Pollora\\Attributes\\Action(hook: 'rest_api_init', priority: 10)]
                public function onRestInit(): void
                {
                    // Register additional routes
                }

                #[\\Pollora\\Attributes\\Action(hook: 'init', priority: 5)]
                #[\\Pollora\\Attributes\\Action(hook: 'admin_init', priority: 10)]
                public function onInit(): void
                {
                    // Multiple hooks on same method
                }

                #[\\Pollora\\Attributes\\Schedule(recurrence: 'daily', hook: 'bench_mixed_cron_{$index}')]
                public function cleanup(): void
                {
                    // Scheduled cleanup
                }
            }

            PHP;
    }

    private function generateHeavyMixed(string $className, int $index, string $slug, ?string $ns = null): string
    {
        $ns ??= $this->namespace;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use Pollora\\Attributes\\PostType;

            #[PostType(slug: '{$slug}', singular: 'Heavy {$index}', plural: 'Heavies {$index}')]
            #[\\Pollora\\Attributes\\PostType\\Supports(features: ['title', 'editor', 'thumbnail', 'excerpt', 'comments', 'revisions', 'custom-fields'])]
            #[\\Pollora\\Attributes\\PostType\\ShowInRest(value: true)]
            #[\\Pollora\\Attributes\\PostType\\HasArchive(value: true)]
            #[\\Pollora\\Attributes\\PostType\\MenuIcon(value: 'dashicons-hammer')]
            #[\\Pollora\\Attributes\\PostType\\MenuPosition(value: 20)]
            #[\\Pollora\\Attributes\\PostType\\Hierarchical(value: true)]
            #[\\Pollora\\Attributes\\PostType\\PublicPostType(value: true)]
            class {$className}
            {
                #[\\Pollora\\Attributes\\Action(hook: 'save_post_{$slug}', priority: 10)]
                #[\\Pollora\\Attributes\\Action(hook: 'wp_insert_post', priority: 20)]
                public function onSave(int \$postId): void
                {
                    // Handle save
                }

                #[\\Pollora\\Attributes\\Filter(hook: 'the_content', priority: 10)]
                public function filterContent(string \$content): string
                {
                    return \$content;
                }

                #[\\Pollora\\Attributes\\Action(hook: 'admin_enqueue_scripts', priority: 10)]
                public function enqueueAdminScripts(): void
                {
                    // Enqueue scripts
                }

                #[\\Pollora\\Attributes\\Filter(hook: 'manage_{$slug}_posts_columns', priority: 10)]
                public function addAdminColumns(array \$columns): array
                {
                    return \$columns;
                }

                #[\\Pollora\\Attributes\\Action(hook: 'manage_{$slug}_posts_custom_column', priority: 10)]
                public function renderAdminColumn(string \$column, int \$postId): void
                {
                    // Render column
                }

                #[\\Pollora\\Attributes\\Schedule(recurrence: 'hourly', hook: 'bench_heavy_sync_{$index}')]
                public function syncData(): void
                {
                    // Scheduled sync
                }

                #[\\Pollora\\Attributes\\Schedule(recurrence: 'daily', hook: 'bench_heavy_cleanup_{$index}')]
                public function cleanupOldData(): void
                {
                    // Scheduled cleanup
                }
            }

            PHP;
    }

    private function generatePlainClass(string $className, ?string $namespace = null): string
    {
        $ns = $namespace ?? $this->namespace;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

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
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
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
