<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Models\DiscoveryLocation;

describe('DiscoveryLocation', function (): void {
    it('can create discovery location', function (): void {
        $location = new DiscoveryLocation('App\\Models', '/path/to/models');

        expect($location->getNamespace())->toBe('App\\Models');
        expect($location->getPath())->toBe('/path/to/models');
    });

    it('generates unique key based on path', function (): void {
        $location1 = new DiscoveryLocation('App\\Models', '/path/to/models');
        $location2 = new DiscoveryLocation('App\\Services', '/path/to/models');
        $location3 = new DiscoveryLocation('App\\Models', '/path/to/services');

        expect($location1->getKey())->toBe($location2->getKey());
        expect($location1->getKey())->not->toBe($location3->getKey());
    });

    it('detects vendor locations', function (): void {
        $vendorLocation = new DiscoveryLocation('Vendor\\Package', '/path/to/vendor/package');
        $appLocation = new DiscoveryLocation('App\\Models', '/path/to/app/models');

        expect($vendorLocation->isVendor())->toBeTrue();
        expect($appLocation->isVendor())->toBeFalse();
    });

    it('detects vendor locations with windows path', function (): void {
        $vendorLocation = new DiscoveryLocation('Vendor\\Package', 'C:\\path\\to\\vendor\\package');

        expect($vendorLocation->isVendor())->toBeTrue();
    });

    it('converts file path to class name', function (): void {
        $location = new DiscoveryLocation('App\\Models', '/app/Models');

        expect($location->toClassName('/app/Models/User.php'))->toBe('App\\Models\\User');
    });

    it('converts nested file path to class name', function (): void {
        $location = new DiscoveryLocation('App\\Services', '/app/Services');

        expect($location->toClassName('/app/Services/Auth/UserService.php'))->toBe('App\\Services\\Auth\\UserService');
    });

    it('returns empty string for file outside location', function (): void {
        $location = new DiscoveryLocation('App\\Models', '/app/Models');

        expect($location->toClassName('/app/Services/UserService.php'))->toBe('');
    });

    it('handles windows paths in class name conversion', function (): void {
        $location = new DiscoveryLocation('App\\Models', 'C:\\app\\Models');

        expect($location->toClassName('C:\\app\\Models\\Auth\\User.php'))->toBe('App\\Models\\Auth\\User');
    });

    it('trims namespace slashes', function (): void {
        $location = new DiscoveryLocation('App\\Models\\', '/app/Models');

        expect($location->toClassName('/app/Models/User.php'))->toBe('App\\Models\\User');
    });
});
