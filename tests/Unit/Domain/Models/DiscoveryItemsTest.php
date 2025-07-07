<?php

declare(strict_types=1);

namespace Tests\Unit\Discovery\Domain\Models;

use Pollora\Discovery\Domain\Models\DiscoveryItems;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Tests\TestCase;

/**
 * Discovery Items Test
 *
 * Tests the DiscoveryItems domain model functionality including
 * item management, location-based organization, and iteration.
 */
final class DiscoveryItemsTest extends TestCase
{
    public function test_can_create_empty_discovery_items(): void
    {
        $items = new DiscoveryItems;

        $this->assertFalse($items->isLoaded());
        $this->assertCount(0, $items);
        $this->assertEquals([], $items->all());
    }

    public function test_can_create_discovery_items_with_initial_data(): void
    {
        $initialData = [
            'location1' => ['item1', 'item2'],
            'location2' => ['item3'],
        ];

        $items = new DiscoveryItems($initialData);

        $this->assertTrue($items->isLoaded());
        $this->assertCount(3, $items);
        $this->assertEquals(['item1', 'item2', 'item3'], $items->all());
    }

    public function test_can_add_single_item_for_location(): void
    {
        $items = new DiscoveryItems;
        $location = new DiscoveryLocation('App\\Models', '/app/models');

        $items->add($location, 'test-item');

        $this->assertTrue($items->hasLocation($location));
        $this->assertEquals(['test-item'], $items->getForLocation($location));
        $this->assertCount(1, $items);
    }

    public function test_can_add_multiple_items_for_location(): void
    {
        $items = new DiscoveryItems;
        $location = new DiscoveryLocation('App\\Models', '/app/models');

        $items->addForLocation($location, ['item1', 'item2', 'item3']);

        $this->assertEquals(['item1', 'item2', 'item3'], $items->getForLocation($location));
        $this->assertCount(3, $items);
    }

    public function test_can_add_items_to_existing_location(): void
    {
        $items = new DiscoveryItems;
        $location = new DiscoveryLocation('App\\Models', '/app/models');

        $items->add($location, 'item1');
        $items->addForLocation($location, ['item2', 'item3']);

        $this->assertEquals(['item1', 'item2', 'item3'], $items->getForLocation($location));
        $this->assertCount(3, $items);
    }

    public function test_can_handle_multiple_locations(): void
    {
        $items = new DiscoveryItems;
        $location1 = new DiscoveryLocation('App\\Models', '/app/models');
        $location2 = new DiscoveryLocation('App\\Services', '/app/services');

        $items->addForLocation($location1, ['model1', 'model2']);
        $items->addForLocation($location2, ['service1']);

        $this->assertCount(3, $items);
        $this->assertEquals(['model1', 'model2'], $items->getForLocation($location1));
        $this->assertEquals(['service1'], $items->getForLocation($location2));
        $this->assertEquals(['model1', 'model2', 'service1'], $items->all());
    }

    public function test_returns_empty_array_for_unknown_location(): void
    {
        $items = new DiscoveryItems;
        $location = new DiscoveryLocation('App\\Models', '/app/models');

        $result = $items->getForLocation($location);

        $this->assertEquals([], $result);
        $this->assertFalse($items->hasLocation($location));
    }

    public function test_can_iterate_over_all_items(): void
    {
        $items = new DiscoveryItems;
        $location1 = new DiscoveryLocation('App\\Models', '/app/models');
        $location2 = new DiscoveryLocation('App\\Services', '/app/services');

        $items->addForLocation($location1, ['model1', 'model2']);
        $items->addForLocation($location2, ['service1']);

        $iteratedItems = [];
        foreach ($items as $item) {
            $iteratedItems[] = $item;
        }

        $this->assertEquals(['model1', 'model2', 'service1'], $iteratedItems);
    }

    public function test_serialization_and_unserialization(): void
    {
        $items = new DiscoveryItems;
        $location = new DiscoveryLocation('App\\Models', '/app/models');

        $items->addForLocation($location, ['item1', 'item2']);

        // Test serialization
        $serialized = $items->__serialize();
        $this->assertIsArray($serialized);

        // Test unserialization
        $newItems = new DiscoveryItems;
        $newItems->__unserialize($serialized);

        $this->assertEquals($items->all(), $newItems->all());
        $this->assertEquals($items->count(), $newItems->count());
    }

    public function test_only_vendor_returns_new_instance(): void
    {
        $items = new DiscoveryItems([
            'location1' => ['item1'],
            'location2' => ['item2'],
        ]);

        $vendorItems = $items->onlyVendor();

        $this->assertNotSame($items, $vendorItems);
        $this->assertInstanceOf(DiscoveryItems::class, $vendorItems);
    }
}
