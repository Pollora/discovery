<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Exceptions\DiscoveryException;
use Pollora\Discovery\Domain\Exceptions\DiscoveryNotFoundException;
use Pollora\Discovery\Domain\Exceptions\InvalidDiscoveryException;

it('names the discovery class that failed, and keeps the cause', function (): void {
    $previous = new LogicException('boom');
    $exception = DiscoveryException::discoveryFailed('App\Discovery', $previous);

    expect($exception->getMessage())->toBe('Discovery failed for class: App\Discovery')
        ->and($exception->getPrevious())->toBe($previous);
});

it('names the discovery class that could not be applied, and keeps the cause', function (): void {
    $previous = new LogicException('boom');
    $exception = DiscoveryException::applicationFailed('App\Discovery', $previous);

    expect($exception->getMessage())->toBe('Failed to apply discovery for class: App\Discovery')
        ->and($exception->getPrevious())->toBe($previous);
});

it('accepts a failure without a cause', function (): void {
    expect(DiscoveryException::discoveryFailed('A')->getPrevious())->toBeNull()
        ->and(DiscoveryException::applicationFailed('A')->getPrevious())->toBeNull();
});

it('says which discovery was not found, by identifier or by class', function (): void {
    $byIdentifier = DiscoveryNotFoundException::withIdentifier('hooks');
    $byClass = DiscoveryNotFoundException::withClass('App\Missing');

    expect($byIdentifier)->toBeInstanceOf(DiscoveryException::class)
        ->and($byIdentifier->getMessage())->toBe('Discovery not found with identifier: hooks')
        ->and($byClass->getMessage())->toBe('Discovery class not found: App\Missing');
});

it('says why a discovery class is invalid', function (): void {
    $invalid = InvalidDiscoveryException::invalidClass('App\Bad', 'Class does not exist');
    $missing = InvalidDiscoveryException::missingInterface('App\Bad', 'App\Contract');
    $duplicate = InvalidDiscoveryException::duplicateIdentifier('hooks');

    expect($invalid)->toBeInstanceOf(DiscoveryException::class)
        ->and($invalid->getMessage())->toBe("Invalid discovery class 'App\\Bad': Class does not exist")
        ->and($missing->getMessage())->toBe("Discovery class 'App\\Bad' must implement 'App\\Contract'")
        ->and($duplicate->getMessage())->toBe("Discovery identifier 'hooks' is already registered");
});
