<?php

declare(strict_types=1);

namespace Pollora\Discovery\Tests\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * Helpers shared by the warm-cache tests and the benchmark: a tree of plain
 * attribute-carrying classes, and a PSR-16 cache that counts its operations
 * and serializes like a file-backed store would.
 */
final class WarmCacheFixtures
{
    /**
     * Write $count class files in $directory and return the directory.
     */
    public static function classes(string $directory, int $count, string $namespace = 'WarmFixtures'): string
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        for ($i = 0; $i < $count; $i++) {
            file_put_contents(sprintf('%s/Item%04d.php', $directory, $i), sprintf(
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\n#[\\Attribute]\nfinal class Item%04d\n{\n    public function run(): int\n    {\n        return %d;\n    }\n}\n",
                $namespace,
                $i,
                $i,
            ));
        }

        return $directory;
    }

    /**
     * Remove a directory tree.
     */
    public static function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }

    /**
     * A PSR-16 cache that stores serialized values (as a file store does) and counts each call.
     */
    public static function countingCache(): CacheInterface
    {
        return new class implements CacheInterface
        {
            /** @var array<string, string> */
            private array $store = [];

            /** @var array<string, int> */
            public array $calls = ['has' => 0, 'get' => 0, 'set' => 0, 'delete' => 0];

            public function get(string $key, mixed $default = null): mixed
            {
                $this->calls['get']++;

                return array_key_exists($key, $this->store) ? unserialize($this->store[$key]) : $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $this->calls['set']++;
                $this->store[$key] = serialize($value);

                return true;
            }

            public function delete(string $key): bool
            {
                $this->calls['delete']++;
                unset($this->store[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->store = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                $this->calls['has']++;

                return array_key_exists($key, $this->store);
            }
        };
    }
}
