<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Models\DiscoveryItems;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;

describe('DiscoveryItems', function (): void {
    it('can create empty discovery items', function (): void {
        $items = new DiscoveryItems;

        expect($items->isLoaded())->toBeFalse();
        expect($items)->toHaveCount(0);
        expect($items->all())->toBe([]);
    });

    it('can create with initial data', function (): void {
        $items = new DiscoveryItems([
            'location1' => ['item1', 'item2'],
            'location2' => ['item3'],
        ]);

        expect($items->isLoaded())->toBeTrue();
        expect($items)->toHaveCount(3);
        expect($items->all())->toEqual(['item1', 'item2', 'item3']);
    });

    it('can add single item for location', function (): void {
        $items = new DiscoveryItems;
        $location = new DiscoveryLocation('App\\Models', '/app/models');

        $items->add($location, 'test-item');

        expect($items->hasLocation($location))->toBeTrue();
        expect($items->getForLocation($location))->toEqual(['test-item']);
        expect($items)->toHaveCount(1);
    });

    it('can add multiple items for location', function (): void {
        $items = new DiscoveryItems;
        $location = new DiscoveryLocation('App\\Models', '/app/models');

        $items->addForLocation($location, ['item1', 'item2', 'item3']);

        expect($items->getForLocation($location))->toEqual(['item1', 'item2', 'item3']);
        expect($items)->toHaveCount(3);
    });

    it('can add items to existing location', function (): void {
        $items = new DiscoveryItems;
        $location = new DiscoveryLocation('App\\Models', '/app/models');

        $items->add($location, 'item1');
        $items->addForLocation($location, ['item2', 'item3']);

        expect($items->getForLocation($location))->toEqual(['item1', 'item2', 'item3']);
        expect($items)->toHaveCount(3);
    });

    it('handles multiple locations', function (): void {
        $items = new DiscoveryItems;
        $location1 = new DiscoveryLocation('App\\Models', '/app/models');
        $location2 = new DiscoveryLocation('App\\Services', '/app/services');

        $items->addForLocation($location1, ['model1', 'model2']);
        $items->addForLocation($location2, ['service1']);

        expect($items)->toHaveCount(3);
        expect($items->getForLocation($location1))->toEqual(['model1', 'model2']);
        expect($items->getForLocation($location2))->toEqual(['service1']);
        expect($items->all())->toEqual(['model1', 'model2', 'service1']);
    });

    it('returns empty array for unknown location', function (): void {
        $items = new DiscoveryItems;
        $location = new DiscoveryLocation('App\\Models', '/app/models');

        expect($items->getForLocation($location))->toBe([]);
        expect($items->hasLocation($location))->toBeFalse();
    });

    it('can iterate over all items', function (): void {
        $items = new DiscoveryItems;
        $location1 = new DiscoveryLocation('App\\Models', '/app/models');
        $location2 = new DiscoveryLocation('App\\Services', '/app/services');

        $items->addForLocation($location1, ['model1', 'model2']);
        $items->addForLocation($location2, ['service1']);

        $iteratedItems = [];
        foreach ($items as $item) {
            $iteratedItems[] = $item;
        }

        expect($iteratedItems)->toEqual(['model1', 'model2', 'service1']);
    });

    it('supports serialization and unserialization', function (): void {
        $items = new DiscoveryItems;
        $location = new DiscoveryLocation('App\\Models', '/app/models');

        $items->addForLocation($location, ['item1', 'item2']);

        $serialized = $items->__serialize();
        expect($serialized)->toBeArray();

        $newItems = new DiscoveryItems;
        $newItems->__unserialize($serialized);

        expect($newItems->all())->toEqual($items->all());
        expect($newItems->count())->toBe($items->count());
    });

    it('onlyVendor returns new instance', function (): void {
        $items = new DiscoveryItems([
            'location1' => ['item1'],
            'location2' => ['item2'],
        ]);

        $vendorItems = $items->onlyVendor();

        expect($vendorItems)->not->toBe($items);
        expect($vendorItems)->toBeInstanceOf(DiscoveryItems::class);
    });
});
