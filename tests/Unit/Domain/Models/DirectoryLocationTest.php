<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Models\DirectoryLocation;

it('exposes its path, as a string too', function (): void {
    $location = new DirectoryLocation('/srv/site/theme');

    expect($location->getPath())->toBe('/srv/site/theme')
        ->and((string) $location)->toBe('/srv/site/theme');
});

it('gives the same key to the same path and another to another path', function (): void {
    expect((new DirectoryLocation('/a'))->getKey())->toBe((new DirectoryLocation('/a'))->getKey())
        ->and((new DirectoryLocation('/a'))->getKey())->not->toBe((new DirectoryLocation('/b'))->getKey());
});

it('exists only for a readable directory', function (): void {
    $dir = sys_get_temp_dir().'/pollora-dirloc-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $file = $dir.'/file.txt';
    file_put_contents($file, 'x');

    try {
        expect((new DirectoryLocation($dir))->exists())->toBeTrue()
            ->and((new DirectoryLocation($file))->exists())->toBeFalse()
            ->and((new DirectoryLocation($dir.'/missing'))->exists())->toBeFalse();
    } finally {
        unlink($file);
        rmdir($dir);
    }
});

it('tells a vendor path from the others, on either directory separator', function (): void {
    expect((new DirectoryLocation('/srv/site/vendor/acme/pkg'))->isVendor())->toBeTrue()
        ->and((new DirectoryLocation('C:\\site\\vendor\\acme'))->isVendor())->toBeTrue()
        ->and((new DirectoryLocation('/srv/site/theme'))->isVendor())->toBeFalse();
});
