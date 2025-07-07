<?php

declare(strict_types=1);

namespace Tests\Unit\Discovery\Domain\Models;

use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Tests\TestCase;

/**
 * Discovery Location Test
 *
 * Tests the DiscoveryLocation domain model functionality including
 * path resolution, namespace handling, and class name conversion.
 */
final class DiscoveryLocationTest extends TestCase
{
    public function test_can_create_discovery_location(): void
    {
        $location = new DiscoveryLocation('App\\Models', '/path/to/models');

        $this->assertEquals('App\\Models', $location->getNamespace());
        $this->assertEquals('/path/to/models', $location->getPath());
    }

    public function test_generates_unique_key(): void
    {
        $location1 = new DiscoveryLocation('App\\Models', '/path/to/models');
        $location2 = new DiscoveryLocation('App\\Services', '/path/to/models');
        $location3 = new DiscoveryLocation('App\\Models', '/path/to/services');

        // Same path should generate same key regardless of namespace
        $this->assertEquals($location1->getKey(), $location2->getKey());

        // Different paths should generate different keys
        $this->assertNotEquals($location1->getKey(), $location3->getKey());
    }

    public function test_detects_vendor_locations(): void
    {
        $vendorLocation = new DiscoveryLocation('Vendor\\Package', '/path/to/vendor/package');
        $appLocation = new DiscoveryLocation('App\\Models', '/path/to/app/models');

        $this->assertTrue($vendorLocation->isVendor());
        $this->assertFalse($appLocation->isVendor());
    }

    public function test_detects_vendor_locations_windows_path(): void
    {
        $vendorLocation = new DiscoveryLocation('Vendor\\Package', 'C:\\path\\to\\vendor\\package');

        $this->assertTrue($vendorLocation->isVendor());
    }

    public function test_converts_file_path_to_class_name(): void
    {
        $location = new DiscoveryLocation('App\\Models', '/app/Models');

        $className = $location->toClassName('/app/Models/User.php');

        $this->assertEquals('App\\Models\\User', $className);
    }

    public function test_converts_nested_file_path_to_class_name(): void
    {
        $location = new DiscoveryLocation('App\\Services', '/app/Services');

        $className = $location->toClassName('/app/Services/Auth/UserService.php');

        $this->assertEquals('App\\Services\\Auth\\UserService', $className);
    }

    public function test_returns_empty_string_for_file_outside_location(): void
    {
        $location = new DiscoveryLocation('App\\Models', '/app/Models');

        $className = $location->toClassName('/app/Services/UserService.php');

        $this->assertEquals('', $className);
    }

    public function test_handles_windows_paths_in_class_name_conversion(): void
    {
        $location = new DiscoveryLocation('App\\Models', 'C:\\app\\Models');

        $className = $location->toClassName('C:\\app\\Models\\Auth\\User.php');

        $this->assertEquals('App\\Models\\Auth\\User', $className);
    }

    public function test_trims_namespace_slashes(): void
    {
        $location = new DiscoveryLocation('App\\Models\\', '/app/Models');

        $className = $location->toClassName('/app/Models/User.php');

        $this->assertEquals('App\\Models\\User', $className);
    }
}
