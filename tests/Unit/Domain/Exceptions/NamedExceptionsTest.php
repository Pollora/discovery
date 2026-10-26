<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Exceptions\CircularDependency;
use Pollora\Discovery\Domain\Exceptions\InstantiationFailed;

it('names the class of a circular dependency, and says how to break the loop', function (): void {
    $exception = CircularDependency::whileBuilding('App\Service');

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toStartWith('Circular dependency detected while building App\Service.')
        ->and($exception->getMessage())->toContain('inject a factory or an interface');
});

it('keeps the reason and the code of a class that could not be instantiated', function (): void {
    $previous = new LogicException('No binding', 7);
    $exception = InstantiationFailed::forClass('App\Service', $previous);

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getPrevious())->toBe($previous)
        ->and($exception->getCode())->toBe(7)
        ->and($exception->getMessage())->toBe('Failed to instantiate class App\Service: No binding');
});
