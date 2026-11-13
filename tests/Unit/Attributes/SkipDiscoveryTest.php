<?php

declare(strict_types=1);

use Pollora\Attributes\SkipDiscovery;

#[SkipDiscovery(except: ['App\Discovery'])]
final class SkipDiscoveryTestAnnotated {}

it('skips every discovery when no exception is given', function (): void {
    expect((new SkipDiscovery)->except)->toBe([]);
});

it('keeps the discoveries it still allows', function (): void {
    expect((new SkipDiscovery(except: ['A', 'B']))->except)->toBe(['A', 'B']);
});

it('can be read back from a class', function (): void {
    $attributes = (new ReflectionClass(SkipDiscoveryTestAnnotated::class))->getAttributes(SkipDiscovery::class);

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]->newInstance()->except)->toBe(['App\Discovery']);
});
