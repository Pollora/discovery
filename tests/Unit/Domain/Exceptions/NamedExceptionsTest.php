<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Exceptions\CircularDependency;
use Pollora\Discovery\Domain\Exceptions\CircularDependencyException;
use Pollora\Discovery\Domain\Exceptions\InstantiationFailedException;

it('names the class of a circular dependency, and says how to break the loop', function (): void {
    $exception = CircularDependencyException::whileBuilding('App\Service');

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toStartWith('Circular dependency detected while building App\Service.')
        ->and($exception->getMessage())->toContain('inject a factory or an interface');
});

it('keeps the reason and the code of a class that could not be instantiated', function (): void {
    $previous = new LogicException('No binding', 7);
    $exception = InstantiationFailedException::forClass('App\Service', $previous);

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getPrevious())->toBe($previous)
        ->and($exception->getCode())->toBe(7)
        ->and($exception->getMessage())->toBe('Failed to instantiate class App\Service: No binding');
});

it('keeps the former name of the circular dependency exception working', function (): void {
    $thrown = null;

    try {
        throw CircularDependency::whileBuilding('App\Service');
    } catch (CircularDependency $caught) {
        $thrown = $caught;
    }

    expect($thrown)->toBeInstanceOf(CircularDependencyException::class)
        ->and(new CircularDependency('x'))->toBeInstanceOf(CircularDependencyException::class);
});
