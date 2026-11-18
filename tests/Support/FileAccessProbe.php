<?php

declare(strict_types=1);

namespace Pollora\Discovery\Tests\Support;

/**
 * Counts the accesses PHP makes to the real file system under one directory.
 *
 * It replaces the `file` stream wrapper, so everything that goes through PHP's
 * streams is seen: fopen/file_get_contents/include (opens), is_file/file_exists/
 * filemtime (stats), scandir and Symfony's Finder (directory opens). The counts
 * are exact and independent of the clock, which makes them usable as a CI
 * assertion ("this path touches 0 files") where a timing would be flaky.
 *
 * Only paths under the watched prefix are counted; every other access is
 * forwarded untouched. Always call stop() (the helpers below do) because the
 * wrapper is process-global.
 *
 * Reusable template for a performance test:
 *   $probe = FileAccessProbe::watch($dir);
 *   ...run the code under test...
 *   $counts = $probe->stop();
 *   expect($counts['total'])->toBe(0);
 * and always pair it with a negative witness that makes the same code read
 * and checks the probe saw it, otherwise a probe that sees nothing passes.
 *
 * @phpstan-type Counts array{opens: int, stats: int, dirs: int, total: int, paths: list<string>}
 */
final class FileAccessProbe
{
    /** @var resource|null */
    public $context;

    private static ?string $prefix = null;

    /** @var array{opens: int, stats: int, dirs: int, paths: array<string, true>} */
    private static array $counts = ['opens' => 0, 'stats' => 0, 'dirs' => 0, 'paths' => []];

    /** @var resource|null */
    private $handle;

    /** @var resource|null */
    private $dirHandle;

    /**
     * Start counting accesses under $directory.
     */
    public static function watch(string $directory): self
    {
        self::$prefix = rtrim((string) realpath($directory), '/').'/';
        self::$counts = ['opens' => 0, 'stats' => 0, 'dirs' => 0, 'paths' => []];

        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);

        return new self;
    }

    /**
     * Stop counting, restore the native wrapper and return what was seen.
     *
     * @return Counts
     */
    public function stop(): array
    {
        if (self::$prefix !== null) {
            @stream_wrapper_restore('file');
            self::$prefix = null;
        }

        $counts = self::$counts;
        $paths = array_keys($counts['paths']);
        sort($paths);

        return [
            'opens' => $counts['opens'],
            'stats' => $counts['stats'],
            'dirs' => $counts['dirs'],
            'total' => $counts['opens'] + $counts['stats'] + $counts['dirs'],
            'paths' => $paths,
        ];
    }

    /**
     * Run a callback under the probe and return its counts.
     *
     * @param  callable(): mixed  $callback
     * @return Counts
     */
    public static function during(string $directory, callable $callback): array
    {
        $probe = self::watch($directory);

        try {
            $callback();
        } finally {
            $counts = $probe->stop();
        }

        return $counts;
    }

    private static function record(string $kind, string $path): void
    {
        if (self::$prefix === null) {
            return;
        }

        $real = realpath($path) ?: $path;

        if (! str_starts_with($real.'/', self::$prefix) && ! str_starts_with($real, self::$prefix)) {
            return;
        }

        self::$counts[$kind]++;
        self::$counts['paths'][$real] = true;
    }

    /**
     * Run a native file function with the real wrapper in place.
     */
    private static function native(callable $callback): mixed
    {
        stream_wrapper_restore('file');

        try {
            return $callback();
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        self::record('opens', $path);

        $this->handle = self::native(fn () => @fopen($path, $mode)) ?: null;

        return $this->handle !== null;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int|false
    {
        return fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_tell(): int|false
    {
        return ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }

    public function stream_lock(int $operation): bool
    {
        return flock($this->handle, $operation);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_truncate(int $size): bool
    {
        return ftruncate($this->handle, $size);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        self::record('stats', $path);

        return self::native(fn () => ($flags & STREAM_URL_STAT_QUIET) ? @stat($path) : stat($path));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        self::record('dirs', $path);

        $this->dirHandle = self::native(fn () => @opendir($path)) ?: null;

        return $this->dirHandle !== null;
    }

    public function dir_readdir(): string|false
    {
        return readdir($this->dirHandle);
    }

    public function dir_rewinddir(): bool
    {
        rewinddir($this->dirHandle);

        return true;
    }

    public function dir_closedir(): bool
    {
        closedir($this->dirHandle);

        return true;
    }

    public function unlink(string $path): bool
    {
        return self::native(fn () => @unlink($path));
    }

    public function rename(string $from, string $to): bool
    {
        return self::native(fn () => @rename($from, $to));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return self::native(fn () => @mkdir($path, $mode, (bool) ($options & STREAM_MKDIR_RECURSIVE)));
    }

    public function rmdir(string $path, int $options): bool
    {
        return self::native(fn () => @rmdir($path));
    }
}
