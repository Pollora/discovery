<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Models\DiscoveryItems;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

final class IsDiscoveryTestSubject implements DiscoveryInterface
{
    use IsDiscovery;

    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void {}

    public function apply(): void {}
}

it('starts with empty items, and keeps handing out the same ones', function (): void {
    $discovery = new IsDiscoveryTestSubject;

    expect($discovery->getItems())->toBeInstanceOf(DiscoveryItems::class)
        ->and($discovery->getItems()->isLoaded())->toBeFalse()
        ->and($discovery->getItems())->toBe($discovery->getItems());
});

it('replaces its items', function (): void {
    $discovery = new IsDiscoveryTestSubject;
    $items = new DiscoveryItems;

    $discovery->setItems($items);

    expect($discovery->getItems())->toBe($items);
});

it('identifies itself by its class name', function (): void {
    expect((new IsDiscoveryTestSubject)->getIdentifier())->toBe(IsDiscoveryTestSubject::class);
});
