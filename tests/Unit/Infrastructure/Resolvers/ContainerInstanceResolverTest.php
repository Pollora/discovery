<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Exceptions\InstantiationFailedException;
use Pollora\Discovery\Infrastructure\Resolvers\ContainerInstanceResolver;
use Psr\Container\ContainerInterface;

it('asks the PSR-11 container for the class', function (): void {
    $instance = new stdClass;
    $container = Mockery::mock(ContainerInterface::class);
    $container->shouldReceive('get')->once()->with(stdClass::class)->andReturn($instance);

    expect((new ContainerInstanceResolver($container))->make(stdClass::class))->toBe($instance);
});

it('refuses what the container hands back when it is not the class that was asked for', function (): void {
    $container = Mockery::mock(ContainerInterface::class);
    $container->shouldReceive('get')->once()->with(ArrayObject::class)->andReturn(new stdClass);

    expect(fn (): object => (new ContainerInstanceResolver($container))->make(ArrayObject::class))
        ->toThrow(InstantiationFailedException::class, 'The container returned stdClass instead of ArrayObject');
});

it('lets the container error through', function (): void {
    $container = Mockery::mock(ContainerInterface::class);
    $container->shouldReceive('get')->andThrow(new RuntimeException('not bound'));

    expect(fn (): object => (new ContainerInstanceResolver($container))->make('App\Service'))
        ->toThrow(RuntimeException::class, 'not bound');
});
